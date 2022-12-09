<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\BkrClient;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\TransactionService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;

class CaptureService
{

    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected UrlService $urlService;

    protected InvoiceService $invoiceService;

    protected FormatRequestParamService $formatRequestParamService;
    
    protected BkrClient $client;

    public function __construct(
        TransactionService $transactionService,
        UrlService $urlService,
        InvoiceService $invoiceService,
        FormatRequestParamService $formatRequestParamService,
        TranslatorInterface $translator,
        BkrClient $client
    ) {
        $this->transactionService = $transactionService;
        $this->urlService = $urlService;
        $this->invoiceService = $invoiceService;
        $this->formatRequestParamService = $formatRequestParamService;
        $this->translator = $translator;
        $this->client = $client;
    }

    public function captureTransaction(
        Request $clientRequest,
        OrderEntity $order,
        Context $context
    )
    {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return;
        }

        $customFields = $this->transactionService->getCustomFields($order, $context);

        $amount       = $order->getAmountTotal();
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';


        if ($amount <= 0) {
            return [
                'status' => false,
                'message' => $this->translation->trans("buckaroo-payment.capture.invalid_amount")
            ];
        }

        if ($customFields['canCapture'] == 0) {
            return [
                'status' => false,
                'message' => $this->translation->trans("buckaroo-payment.capture.capture_not_supported")
            ];
        }

        if (!empty($customFields['captured']) && ($customFields['captured'] == 1)) {
            return [
                'status' => false,
                'message' => $this->translation->trans("buckaroo-payment.capture.already_captured")
            ];
        }

        $request = new TransactionRequest;
        $request->setClientIPAndAgent($clientRequest);
        $request->setServiceAction('Pay');
        $request->setDescription('');
        $request->setServiceName($customFields['serviceName']);
        $request->setAmountCredit(0);
        $request->setAmountDebit($amount);
        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency($currencyCode);
        $request->setOriginalTransactionKey($customFields['originalTransactionKey']);
        $request->setServiceVersion($customFields['version']);

        $request->setAdditionalParameter('orderTransactionId', $order->getTransactions()->last()->getId());
        $request->setAdditionalParameter('orderId', $order->getId());

        $request->setPushURL($this->urlService->getReturnUrl('buckaroo.payment.push'));

        if ($customFields['serviceName'] == 'Billink') {
            $request->setServiceAction('Capture');
            $additional = $this->getBillinkArticleData($amount);
            foreach ($additional as $key2 => $item3) {
                foreach ($item3 as $key => $value) {
                    $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
                }
            }
        }

        if ($customFields['serviceName'] == 'klarnakp') {
            $orderItems = $this->formatRequestParamService->getProductLineDataCapture($order);
            foreach ($orderItems as $value) {
                $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
            }

            $request->setServiceParameter('ReservationNumber', $customFields['reservationNumber']);
        }

        $salesChannelId = $order->getSalesChannelId();
        $url       = $this->urlService->getTransactionUrl($customFields['serviceName'], $salesChannelId);
        $this->client->setSalesChannelId($salesChannelId);
        $response  = $this->client->post($url, $request, TransactionResponse::class);

        if ($response->isSuccess()) {
            if (
                !$this->invoiceService->isInvoiced($order->getId(), $context) &&
                !$this->invoiceService->isCreateInvoiceAfterShipment(false, $customFields['serviceName'], $salesChannelId)
            ) {
                $this->invoiceService->generateInvoice($order, $context, $order->getId());
            }

            return [
                'status' => true,
                'message' => $this->translation->trans(
                    "buckaroo-payment.capture.already_captured",
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