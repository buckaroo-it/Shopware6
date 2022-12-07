<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\BkrClient;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;

class RefundService
{

    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    protected SettingsService $settingsService;

    protected UrlService $urlService;

    protected StateTransitionService $stateTransitionService;

    protected BkrClient $client;

    public function __construct(
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        SettingsService $settingsService,
        TransactionService $transactionService,
        UrlService $urlService,
        StateTransitionService $stateTransitionService,
        TranslatorInterface $translator,
        BkrClient $client
    ) {
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->transactionService = $transactionService;
        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->stateTransitionService = $stateTransitionService;
        $this->translator = $translator;
        $this->client = $client;
        
    }
    public function refundTransaction(
        Request $clientRequest,
        OrderEntity $order,
        Context $context,
        $item,
        &$orderItems = [],
        $customRefundAmount = 0
    ) {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return;
        }

        $customFields = $this->transactionService->getCustomFields($order, $context);
        $customFields['serviceName']            = $item['transaction_method'];
        $customFields['originalTransactionKey'] = $item['transactions'];

        $serviceName = (in_array($customFields['serviceName'], ['creditcard', 'creditcards', 'giftcards'])) ? $customFields['brqPaymentMethod'] : $customFields['serviceName'];

        $amount = 0;
        if ($customRefundAmount && !in_array($serviceName, ['afterpay', 'Billink', 'klarnakp'])) {
            $amount = $customRefundAmount;
        } else {
            if (!empty($orderItems) && is_array($orderItems)) {
                foreach ($orderItems as $orderItem) {
                    if (isset($orderItem['totalAmount'])) {
                        $amount = $amount + $orderItem['totalAmount'];
                    }
                }
            }
        }

        if ($amount <= 0) {
            $amount = $item['amount']; //backward compatibility only or in case no $orderItems was passed
        }
        $currency = !empty($item['currency']) ? $item['currency'] : 'EUR';

        if ($amount <= 0) {
            return false;
        }

        if ($customFields['canRefund'] == 0) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.refund.not_supported")
            ];
        }

        if (!empty($customFields['refunded']) && ($customFields['refunded'] == 1)) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.refund.already_refunded")
            ];
        }

        $request = new TransactionRequest;
        $request->setClientIPAndAgent($clientRequest);
        $request->setServiceAction('Refund');
        $request->setDescription(
            $this->settingsService->getParsedLabel($order, $order->getSalesChannelId(), 'refundLabel')
        );
        $request->setServiceName($serviceName);
        $request->setAmountCredit($amount ? $amount : $order->getAmountTotal());
        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency($currency);
        $request->setOriginalTransactionKey($customFields['originalTransactionKey']);
        $request->setServiceVersion($customFields['version']);

        if ($customFields['serviceName'] == 'afterpaydigiaccept') {
            $this->setRefundRequestArticlesForAfterpayOld($request, $amount);
        }

        $additional = [];
        
        if ($customFields['serviceName'] == 'afterpay') {
            $request->setInvoice($this->transactionService->getRefundTransactionInvoceId($order->getOrderNumber(), $order->getTransactions()->last()->getId(), $customFields));
            $additional = $this->getRefundArticleData($amount);
        }

        if ($customFields['serviceName'] == 'Billink') {
            $additional = $this->getBillinkArticleData($amount);
        }

        if ($customFields['serviceName'] == 'klarnakp') {
            $additional = $this->getRefundArticleData($amount);
        }
        
        foreach ($additional as $item3) {
            foreach ($item3 as $value) {
                $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
            }
        }
        if ($customFields['serviceName'] == 'sepadirectdebit') {
            $request->setChannelHeader('Backoffice');
        }

        $salesChannelId = $order->getSalesChannelId();

        $url       = $this->urlService->getTransactionUrl($customFields['serviceName'], $salesChannelId);
        $this->client->setSalesChannelId($salesChannelId);

        $response  = $this->client->post($url, $request, TransactionResponse::class);

        if ($response->isSuccess()) {
            $transaction = $order->getTransactions()->first();
            $status      = ($amount < $order->getAmountTotal()) ? 'partial_refunded' : 'refunded';
            $this->stateTransitionService->transitionPaymentState($status, $transaction->getId(), $context);
            $this->transactionService->saveTransactionData($transaction->getId(), $context, [$status => 1]);

            // updating refunded items in transaction
            if ($orderItems) {
                foreach ($orderItems as $value) {
                    if (isset($value['id'])) {
                        $orderItemsRefunded[$value['id']] = $value['quantity'];
                    }
                }
                $orderItems = '';

                $refunded_items = $this->buckarooTransactionEntityRepository->getById($item['id'])->get("refunded_items");
                if ($refunded_items) {
                    $refunded_items = json_decode($refunded_items);
                    foreach ($refunded_items as $k => $qnt) {
                        if ($orderItemsRefunded[$k]) {
                            $orderItemsRefunded[$k] = (int)$orderItemsRefunded[$k] + (int)$qnt;
                        } else {
                            $orderItemsRefunded[$k] = (int)$qnt;
                        }
                    }
                }

                $this->buckarooTransactionEntityRepository->save($item['id'], ['refunded_items' => json_encode($orderItemsRefunded)], []);
            }

            return [
                'status' => true,
                'message' => $this->translator->trans(
                    "buckaroo-payment.refund.refunded_amount",
                    [
                        "%amount%" => $amount,
                        "%currency%" => $currency
                    ]
                )
            ];
        }

        return [
            'status'  => false,
            'message' => $response->getSubCodeMessageFull() ?? $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }
    private function getRefundArticleData($amount)
    {

        $additional[] = [
            [
                '_'       => 'Return',
                'Name'    => 'RefundType',
                'GroupID' => 1,
                'Group'   => 'Article',
            ], [
                '_'       => 'Refund',
                'Name'    => 'Description',
                'GroupID' => 1,
                'Group'   => 'Article',
            ], [
                '_'       => 'Refund',
                'Name'    => 'Description',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
            [
                '_'       => '1',
                'Name'    => 'Identifier',
                'Group'   => 'Article',
                'GroupID' => 1,
            ],
            [
                '_'       => '1',
                'Name'    => 'Quantity',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
            [
                '_'       => $amount,
                'Name'    => 'GrossUnitPrice',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
            [
                '_'       => 0,
                'Name'    => 'VatPercentage',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
        ];

        return $additional;
    }

    private function setRefundRequestArticlesForAfterpayOld(TransactionRequest &$request, float $amount)
    {
        $key =  'refund_article';

        $request->setServiceParameter('ArticleId', 'refund_article', 'Article', $key);
        $request->setServiceParameter('ArticleDescription', 'refund_article', 'Article', $key);
        $request->setServiceParameter('ArticleQuantity', 1, 'Article', $key);
        $request->setServiceParameter('ArticleUnitPrice', round($amount, 2), 'Article', $key);
        $request->setServiceParameter('ArticleVatCategory',4, 'Article', $key);
    }

    private function getBillinkArticleData($amount)
    {
        $additional[] = [
            [
                '_'       => $amount,
                'Name'    => 'GrossUnitPriceIncl',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
            [
                '_'       => 0,
                'Name'    => 'VatPercentage',
                'GroupID' => 1,
                'Group'   => 'Article',
            ],
        ];

        return $additional;
    }
}
