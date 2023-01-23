<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Resources\Constants\IPProtocolVersion;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;

class RefundService
{

    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    protected SettingsService $settingsService;

    protected UrlService $urlService;

    protected StateTransitionService $stateTransitionService;

    protected ClientService $clientService;

    public function __construct(
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        SettingsService $settingsService,
        TransactionService $transactionService,
        UrlService $urlService,
        StateTransitionService $stateTransitionService,
        TranslatorInterface $translator,
        ClientService $clientService
    ) {
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->transactionService = $transactionService;
        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->stateTransitionService = $stateTransitionService;
        $this->translator = $translator;
        $this->clientService = $clientService;
        
    }

    /**
     * Do a buckaroo refund request
     *
     * @param Request $request
     * @param OrderEntity $order
     * @param Context $context
     *
     * @return array|null
     */
    public function refund(
        Request $request,
        OrderEntity $order,
        Context $context,
        array $transaction
    ): ?array
    {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return null;
        }

        $orderItems = $request->get('orderItems');


        $customFields = $this->transactionService->getCustomFields($order, $context);
        $paymentCode = $this->getPaymentCode($order, $context, $transaction);
        $validationErrors = $this->validate($order, $customFields);

        if($validationErrors !== null) {
            return $validationErrors;
        }

        $client = $this->getClient(
            $paymentCode,
            $order->getSalesChannelId()
        );

        $amount = $this->determineAmount(
            $orderItems,
            (float)$request->get('customRefundAmount'),
            (float)$transaction['amount'],
            $paymentCode
        );

        return $this->handleResponse(
            $client->execute(
                array_merge_recursive(
                    $this->getCommonRequestPayload(
                        $request,
                        $order,
                        $customFields['originalTransactionKey'],
                        $amount
                    ),
                    $this->getMethodPayload(
                        $amount,
                        $paymentCode
                    )
                ),
                'refund'
            ),
            $order,
            $context,
            $orderItems,
            $transaction['id'],
            $amount
        );
    }


   /**
    * Handle response from payment engine
    *
    * @param ClientResponseInterface $response
    * @param OrderEntity $order
    * @param Context $context
    * @param array $orderItems
    * @param string $transactionId
    * @param float $amount
    *
    * @return array
    */
    private function handleResponse(
        ClientResponseInterface $response,
        OrderEntity $order,
        Context $context,
        array $orderItems,
        string $transactionId,
        float $amount
    ): array
    {
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

                $refunded_items = $this->buckarooTransactionEntityRepository->getById($transactionId)->get("refunded_items");
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

                $this->buckarooTransactionEntityRepository->save($transactionId, ['refunded_items' => json_encode($orderItemsRefunded)], []);
            }

            return [
                'status' => true,
                'message' => $this->translator->trans("buckaroo-payment.refund.refunded_amount"),
                'amount' => sprintf(" %s %s",$amount, $order->getCurrency()->getIsoCode())
            ];
        }

        return [
            'status'  => false,
            'message' => $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }
    
     /**
     * Get parameters common to all payment methods
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array
     */
    private function getCommonRequestPayload(
        Request $request,
        OrderEntity $order,
        string $transactionKey,
        float $amount
    ): array
    {
        return [
            'order'                  => $order->getOrderNumber(),
            'invoice'                => $order->getOrderNumber(),
            'amountCredit'           => $amount,
            'currency'               => $order->getCurrency()->getIsoCode(),
            'pushURL'                => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'clientIP'               => $this->getIp($request),
            'originalTransactionKey' => $transactionKey,
            'additionalParameters'   => [
                'orderTransactionId' => $order->getTransactions()->last()->getId(),
                'orderId' => $order->getId(),
            ],
        ];
    }

    /**
     * Get method specific payloads
     *
     * @param float $amount
     * @param string $paymentCode
     *
     * @return array
     */
    private function getMethodPayload(
        float $amount,
        string $paymentCode
    ): array {
        if(in_array($paymentCode, ["afterpay", "Billink", "klarnakp"])) {
            return $this->getRefundArticleData($amount);
        }
        if ($paymentCode == 'afterpaydigiaccept') {
            return $this->getRefundRequestArticlesForAfterpayOld($amount);
        }

       
        return [];
    }

    /**
     * Validate request and return any errors
     *
     * @param OrderEntity $order
     * @param array $customFields
     *
     * @return array|null
     */
    private function validate(OrderEntity $order, array $customFields): ?array
    {

        if ($order->getAmountTotal() <= 0) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.capture.invalid_amount")
            ];
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

        if(!isset($customFields['originalTransactionKey'])) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.general_error")
            ];
        }
        return null;
    }

    /**
     * Get buckaroo client
     *
     * @param string $paymentCode
     * @param string $salesChannelId
     *
     * @return Client
     */
    private function getClient(string $paymentCode, string $salesChannelId): Client
    {
        return $this->clientService
            ->get($paymentCode, $salesChannelId);
    }


    /**
     * Get client ip
     *
     * @param Request $request
     *
     * @return void
     */
    private function getIp(Request $request)
    {
        $remoteIp = $request->getClientIp();

        return [
            'address'       =>  $remoteIp,
            'type'          => IPProtocolVersion::getVersion($remoteIp)
        ];
    }
    public function determineAmount(
        array $orderItems,
        float $customRefundAmount,
        float $transactionAmount,
        string $paymentCode
    ): float
    {
        $amount = 0;
        if ($customRefundAmount && !in_array($paymentCode, ['afterpay', 'Billink', 'klarnakp'])) {
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
            $amount = $transactionAmount; //backward compatibility only or in case no $orderItems was passed
        }
        return $amount;
    }
    
    public function getPaymentCode(OrderEntity $order, Context $context, array $transaction)
    {
        $customFields = $this->transactionService->getCustomFields($order, $context);
        $customFields['serviceName']            = $transaction['transaction_method'];
        $customFields['originalTransactionKey'] = $transaction['transactions'];

        return (in_array($customFields['serviceName'], ['creditcard', 'creditcards', 'giftcards'])) ? $customFields['brqPaymentMethod'] : $customFields['serviceName'];

    }

    private function getRefundArticleData(float $amount): array
    {

        return [
            'identifier'        => 1,
            'description'       => 'Refund',
            'quantity'          => 1,
            'price'             =>  round($amount, 2),
            'vatPercentage'     => 0,
        ];
    }

    private function getRefundRequestArticlesForAfterpayOld(float $amount): array
    {

        return [
            'identifier'        => 1,
            'description'       => 'Refund',
            'quantity'          => 1,
            'price'             =>  round($amount, 2),
            'vatCategory'       => 0,
        ];

    }

}
