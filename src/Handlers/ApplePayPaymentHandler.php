<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\ApplePay;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class ApplePayPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = ApplePay::class;

    /**
     * Get parameters for specific payment method
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        $applePayInfo = $dataBag->get('applePayInfo');
        // TEMP DIAGNOSTIC: does the Apple Pay token reach the handler?
        $this->asyncPaymentService->logger->info('[ApplePay][getMethodPayload]', [
            'dataBagKeys'          => array_keys($dataBag->all()),
            'applePayInfoIsString' => is_string($applePayInfo),
            'applePayInfoLength'   => is_string($applePayInfo) ? strlen($applePayInfo) : 0,
        ]);

        if (!is_string($applePayInfo)) {
            return [];
        }

        $data = json_decode($applePayInfo);
        // json_decode() returns null on failure (never false)
        if ($data === null || !is_object($data)) {
            return [];
        }

        return [
            "customerCardName" => $this->getCustomerName($data, $order),
            "paymentData" => $this->getPaymentData($data)
        ];
    }

    /**
     * @param mixed $data
     * @return string
     */
    private function getPaymentData($data): string
    {
        if (!is_object($data)) {
            return '';
        }
        if (empty($data->token)) {
            return '';
        }

        $data = json_encode($data->token);
        if ($data === false) {
            return '';
        }
        return base64_encode($data);
    }

    /**
     * Card holder name sent to Buckaroo (shown as the customer in Plaza).
     * Prefer the Apple Pay billing contact, fall back to the shipping contact
     * (express flow) and finally to the order customer — in the standard
     * checkout the shop always knows the customer, so the transaction should
     * never end up as "Customer Unknown".
     *
     * @param mixed $data
     * @param OrderEntity $order
     * @return string
     */
    private function getCustomerName($data, OrderEntity $order): string
    {
        if (is_object($data)) {
            foreach (['billingContact', 'shippingContact'] as $contactKey) {
                if (!empty($data->{$contactKey}) &&
                    !empty($data->{$contactKey}->givenName) &&
                    !empty($data->{$contactKey}->familyName)
                ) {
                    return $data->{$contactKey}->givenName . ' ' . $data->{$contactKey}->familyName;
                }
            }
        }

        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer !== null) {
            return trim(($orderCustomer->getFirstName() ?? '') . ' ' . ($orderCustomer->getLastName() ?? ''));
        }

        return '';
    }
}

