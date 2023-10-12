<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

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

    public function refundAll(
        Request $request,
        OrderEntity $order,
        Context $context,
        array $transactionsToRefund
    ): array {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            throw new \InvalidArgumentException('Cannot do refunds');
        }

        $orderItems = $request->get('orderItems');

        if (!is_array($orderItems)) {
            throw new \InvalidArgumentException('OrderItems must be an array');
        }


        $customFields = $this->transactionService->getCustomFields($order, $context);
        $configCode = $this->getConfigCode($customFields);
        $validationErrors = $this->validate($order, $customFields);

        if ($validationErrors !== null) {
            return [$validationErrors];
        }

        $amountRemaining = $this->getMaxAmount(
            $orderItems,
            $request->get('customRefundAmount'),
            $configCode
        );

        $responses = [];
        foreach ($transactionsToRefund as $item) {
            if ($amountRemaining <= 0) {
                break;
            }

            if (is_array($item) && isset($item['amount']) && is_scalar($item['amount'])) {
                $amount = (float)$item['amount'];

                $diff = $amountRemaining - $amount;

                if ($diff < 0) {
                    $amount = $amountRemaining;
                    $amountRemaining = 0;
                } else {
                    $amountRemaining = round($diff, 2);
                }

                if ($amount <= 0) {
                    continue;
                }

                if (
                    $configCode === 'giftcards' &&
                    $amount < (float)$item['amount'] &&
                    isset($item['transaction_method']) &&
                    $item['transaction_method'] !== 'fashioncheque'
                ) {
                    $requiredAmount = sprintf(" %s %s", (float)$item['amount'], $this->getCurrencyIso($order));
                    $responses[] = [
                        'status' => false,
                        'message' => "Cannot partial refund giftcard, minimum required amount is {$requiredAmount}"
                    ];
                    continue;
                }

                $responses[] = $this->refund(
                    $request,
                    $order,
                    $context,
                    $item,
                    $amount,
                    $configCode,
                    $orderItems
                );
            }
        }

        return $responses;
    }

    /**
     * Do a buckaroo refund request
     *
     * @param Request $request
     * @param OrderEntity $order
     * @param Context $context
     * @param array<mixed> $transaction
     * @param float $amount
     * @param string $configCode
     * @param array<mixed> $orderItems
     *
     * @return array<mixed>|null
     */
    public function refund(
        Request $request,
        OrderEntity $order,
        Context $context,
        array $transaction,
        float $amount,
        string $configCode,
        array $orderItems
    ): ?array {
        $client = $this->getClient(
            $configCode,
            $order->getSalesChannelId()
        )
            ->setAction('refund')
            ->setPayload(
                array_merge_recursive(
                    $this->getCommonRequestPayload(
                        $request,
                        $order,
                        $transaction['transactions'],
                        $amount
                    ),
                    $this->getMethodPayload(
                        $amount,
                        $configCode,
                        $transaction
                    )
                )
            );

        if (
            $configCode === 'giftcards' &&
            isset($transaction['transaction_method']) &&
            is_string($transaction['transaction_method'])
        ) {
            $client->setPaymentCode($transaction['transaction_method']);
        }

        //Override payByBank if transaction was made with ideal
        if ($configCode === 'paybybank' && $transaction['transaction_method'] === 'ideal') {
            $client->setPaymentCode($transaction['transaction_method']);
        }


        return $this->handleResponse(
            $client->execute(),
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
     * @param array<mixed> $orderItems
     * @param mixed $transactionId
     * @param float $amount
     *
     * @return array<mixed>
     */
    private function handleResponse(
        ClientResponseInterface $response,
        OrderEntity $order,
        Context $context,
        array $orderItems,
        $transactionId,
        float $amount
    ): array {
        if (!is_scalar($transactionId)) {
            throw new \InvalidArgumentException('Transaction id must be a string');
        }
        $transactionId = (string)$transactionId;

        $transaction = $this->getLastTransaction($order);

        if ($response->isSuccess()) {
            $status      = ($amount < $order->getAmountTotal()) ? 'partial_refunded' : 'refunded';
            $this->stateTransitionService->transitionPaymentState($status, $transaction->getId(), $context);
            $this->transactionService->saveTransactionData($transaction->getId(), $context, [$status => 1]);

            // updating refunded items in transaction
            if (count($orderItems)) {
                $orderItemsRefunded = [];
                foreach ($orderItems as $value) {
                    if (
                        is_array($value) &&
                        isset($value['id']) &&
                        isset($value['quantity']) &&
                        is_string($value['id']) &&
                        is_scalar($value['quantity'])
                    ) {
                        $orderItemsRefunded[$value['id']] = $value['quantity'];
                    }
                }
                $orderItems = '';

                $refunded_items = '';

                $bkTransaction = $this->buckarooTransactionEntityRepository
                    ->getById($transactionId);
                if ($bkTransaction !== null) {
                    $refunded_items = $bkTransaction->get("refunded_items");
                }

                if (!is_string($refunded_items)) {
                    $refunded_items = '';
                }

                if (!empty($refunded_items)) {
                    $refunded_items = json_decode($refunded_items, true);
                    if (is_array($refunded_items)) {
                        foreach ($refunded_items as $k => $qnt) {
                            if (!is_scalar($qnt)) {
                                $qnt = 0;
                            }
                            $qnt = (int)$qnt;
                            if (!isset($orderItemsRefunded[$k])) {
                                $orderItemsRefunded[$k] = 0;
                            }
                            $orderItemsRefunded[$k] = $orderItemsRefunded[$k] + $qnt;
                        }
                    }
                }

                $amountCredit = 0;
                $transaction = $this->buckarooTransactionEntityRepository->getById($transactionId);
                if ($transaction !== null && is_scalar($transaction->get('amount_credit'))) {
                    $amountCredit = (float)$transaction->get('amount_credit');
                }


                $this->buckarooTransactionEntityRepository
                    ->save(
                        $transactionId,
                        [
                            'refunded_items' => json_encode($orderItemsRefunded),
                            'amount_credit' => (string)($amountCredit + $amount)
                        ],
                    );
            }

            return [
                'status' => true,
                'message' => $this->translator->trans("buckaroo-payment.refund.refunded_amount"),
                'amount' => sprintf(" %s %s", $amount, $this->getCurrencyIso($order))
            ];
        }

        return [
            'status'  => false,
            'message' => $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }

    protected function getLastTransaction(OrderEntity $order): OrderTransactionEntity
    {
        $transactions = $order->getTransactions();

        if ($transactions === null) {
            throw new \InvalidArgumentException('Cannot find last transaction on order');
        }

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity|null */
        $transaction = $transactions->last();

        if ($transaction === null) {
            throw new \InvalidArgumentException('Cannot find last transaction on order');
        }

        return $transaction;
    }
    protected function getCurrencyIso(OrderEntity $order): string
    {
        $currency = $order->getCurrency();
        if ($currency === null) {
            throw new \InvalidArgumentException('Cannot find currency on order');
        }
        return $currency->getIsoCode();
    }

    /**
     * Get parameters common to all payment methods
     *
     * @param Request $request
     * @param OrderEntity $order
     * @param mixed $transactionKey
     * @param float $amount
     *
     * @return array<mixed>
     */
    private function getCommonRequestPayload(
        Request $request,
        OrderEntity $order,
        $transactionKey,
        float $amount
    ): array {

        if (!is_scalar($transactionKey)) {
            $transactionKey = '';
        }

        $transaction = $this->getLastTransaction($order);
        return [
            'order'                  => $order->getOrderNumber(),
            'invoice'                => $order->getOrderNumber(),
            'amountCredit'           => $amount,
            'currency'               => $this->getCurrencyIso($order),
            'pushURL'                => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'pushURLFailure'         => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'clientIP'               => $this->getIp($request),
            'originalTransactionKey' => (string)$transactionKey,
            'additionalParameters'   => [
                'orderTransactionId' => $transaction->getId(),
                'orderId' => $order->getId(),
            ],
        ];
    }

    /**
     * Get method specific payloads
     *
     * @param float $amount
     * @param string $configCode
     * @param array $transaction
     *
     * @return array<mixed>
     */
    private function getMethodPayload(
        float $amount,
        string $configCode,
        array $transaction
    ): array {
        $payload = [];

        if (
            $configCode === "afterpay" &&
            $this->settingsService->getSetting('afterpayEnabledold') === true
        ) {
            $payload = $this->getRefundRequestArticlesForAfterpayOld($amount);
        }

        if (in_array($configCode, ["afterpay", "Billink", "klarnakp"])) {
            $payload = $this->getRefundArticleData($amount);
        }

        if (
            in_array($configCode, ['creditcard', 'creditcards', 'giftcards']) &&
            isset($transaction['transaction_method']) &&
            is_string($transaction['transaction_method'])
        ) {
            $payload = [
                "name" => $transaction['transaction_method'],
                "version" => 2
            ];
        }

        return $payload;
    }


    /**
     * Validate request and return any errors
     *
     * @param OrderEntity $order
     * @param array<mixed> $customFields
     *
     * @return array<mixed>|null
     */
    private function validate(OrderEntity $order, array $customFields): ?array
    {
        $error = null;

        if ($order->getAmountTotal() <= 0) {
            $error = [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.capture.invalid_amount")
            ];
        }

        if ($customFields['canRefund'] == 0) {
            $error = [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.refund.not_supported")
            ];
        }

        if (!empty($customFields['refunded']) && ($customFields['refunded'] == 1)) {
            $error = [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.refund.already_refunded")
            ];
        }

        if (!isset($customFields['originalTransactionKey'])) {
            $error = [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.general_error")
            ];
        }
        return $error;
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
     * @return array<mixed>
     */
    private function getIp(Request $request): array
    {
        $remoteIp = $request->getClientIp();

        return [
            'address'       =>  $remoteIp,
            'type'          => IPProtocolVersion::getVersion($remoteIp)
        ];
    }

    /**
     * @param array<mixed> $orderItems
     * @param mixed $customRefundAmount
     * @param string $paymentCode
     *
     * @return float
     */
    public function getMaxAmount(
        array $orderItems,
        $customRefundAmount,
        string $paymentCode
    ): float {
        $amount = 0;

        if (
            is_scalar($customRefundAmount) &&
            $this->isCustomRefundAmount($customRefundAmount, $paymentCode)
        ) {
            $amount = (float)$customRefundAmount;
        } else {
            if (!empty($orderItems) && is_array($orderItems)) {
                foreach ($orderItems as $orderItem) {
                    if (isset($orderItem['totalAmount'])) {
                        $amount = $amount + $orderItem['totalAmount'];
                    }
                }
            }
        }
        return $amount;
    }



    /**
     * Is custom refund amount
     *
     * @param mixed $customRefundAmount
     * @param string $paymentCode
     *
     * @return boolean
     */
    private function isCustomRefundAmount($customRefundAmount, string $paymentCode)
    {
        return is_scalar($customRefundAmount) &&
            (float)$customRefundAmount > 0 &&
            !in_array($paymentCode, ['afterpay', 'Billink', 'klarnakp']);
    }

    /**
     * @param array<mixed> $customFields
     *
     * @return string
     */
    public function getConfigCode(
        array $customFields
    ): string {
        if (!is_string($customFields['serviceName'])) {
            throw new \InvalidArgumentException('Service name is not a string');
        }
        return $customFields['serviceName'];
    }

    /**
     * @param float $amount
     *
     * @return array<mixed>
     */
    private function getRefundArticleData(float $amount): array
    {

        return [
            'articles' => [[
                'refundType'        => 'Return',
                'identifier'        => 1,
                'description'       => 'Refund',
                'quantity'          => 1,
                'price'             =>  round($amount, 2),
                'vatPercentage'     => 0,
            ]]
        ];
    }

    /**
     * @param float $amount
     *
     * @return array<mixed>
     */
    private function getRefundRequestArticlesForAfterpayOld(float $amount): array
    {

        return [
            'articles' => [[
                'identifier'        => 1,
                'description'       => 'Refund',
                'quantity'          => 1,
                'price'             => round($amount, 2),
                'vatCategory'       => 4,
            ]]
        ];
    }
}
