<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\Refund\Builder;
use Buckaroo\Shopware6\Service\TransactionService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\Refund\OrderRefundData;
use Buckaroo\Shopware6\Buckaroo\Refund\Order\PaymentRecord;
use Buckaroo\Shopware6\Buckaroo\Refund\RefundDataInterface;
use Buckaroo\Shopware6\Service\Refund\ResponseHandler;

class RefundService
{
    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected Builder $refundBuilder;

    protected ResponseHandler $refundResponseHandler;

    public function __construct(
        TransactionService $transactionService,
        TranslatorInterface $translator,
        Builder $refundBuilder,
        ResponseHandler $refundResponseHandler
    ) {
        $this->transactionService = $transactionService;
        $this->translator = $translator;
        $this->refundBuilder = $refundBuilder;
        $this->refundResponseHandler = $refundResponseHandler;
    }

    /**
     * Do a buckaroo refund request
     *
     * @param Request $request
     * @param OrderEntity $order
     * @param Context $context
     * @param array<mixed> $transaction
     *
     * @return array<mixed>|null
     */
    public function refund(
        Request $request,
        OrderEntity $order,
        Context $context,
        array $transaction
    ): ?array {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return null;
        }

        $orderItems = $request->get('orderItems');

        if (!is_array($orderItems)) {
            throw new \InvalidArgumentException('OrderItems must be an array');
        }


        $customFields = $this->transactionService->getCustomFields($order, $context);
        $configCode = $this->getConfigCode($customFields);
        $validationErrors = $this->validate($order, $customFields);

        if ($validationErrors !== null) {
            return $validationErrors;
        }

        $amount = $this->determineAmount(
            $orderItems,
            $request->get('customRefundAmount'),
            $transaction['amount'],
            $configCode
        );

        if ($amount <= 0) {
            return [];
        }
        return $this->handleRefund(
            new OrderRefundData(
                $order,
                new PaymentRecord($transaction),
                $amount
            ),
            $request,
            $context,
            $orderItems,
            $configCode
        );
    }

    protected function handleRefund(
        RefundDataInterface $refundData,
        Request $request,
        Context $context,
        array $orderItems,
        string $configCode
    ): array {
        $client = $this->refundBuilder->build(
            $refundData,
            $request,
            $configCode
        );

        return $this->refundResponseHandler->handle(
            $client->execute(),
            $refundData,
            $context,
            $orderItems,
        );
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

        if ($order->getAmountTotal() <= 0) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.refund.invalid_amount")
            ];
        }

        if ($customFields['canRefund'] == 0) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.refund.not_supported")
            ];
        }

        if (!empty($customFields['refunded']) && ($customFields['refunded'] == 1)) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.refund.already_refunded")
            ];
        }

        if (!isset($customFields['originalTransactionKey'])) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.refund.general_error")
            ];
        }
        return null;
    }
    
    /**
     *
     * @param array<mixed> $orderItems
     * @param mixed $customRefundAmount
     * @param mixed $transactionAmount
     * @param string $paymentCode
     *
     * @return float
     */
    public function determineAmount(
        array $orderItems,
        $customRefundAmount,
        $transactionAmount,
        string $paymentCode
    ): float {
        $amount = 0;
        if (
            is_scalar($customRefundAmount) &&
            (float)$customRefundAmount > 0 &&
            !in_array($paymentCode, ['afterpay', 'Billink', 'klarnakp'])
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

            if (is_scalar($transactionAmount) && $amount > (float)$transactionAmount) {
                $amount = (float)$transactionAmount;
            }
        }

        if ($amount <= 0 && is_scalar($transactionAmount)) {
            $amount = (float)$transactionAmount; //backward compatibility only or in case no $orderItems was passed
        }
        return $amount;
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
}
