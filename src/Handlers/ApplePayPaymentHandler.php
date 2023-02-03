<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\ApplePay;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class ApplePayPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = ApplePay::class;

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
    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        $applePayInfo = $dataBag->get('applePayInfo');

        if (!is_string($applePayInfo)) {
            return [];
        }

        $data = json_decode($applePayInfo);
        if ($data === false || !is_object($data)) {
            return [];
        }

        return [
            "customerCardName" => $this->getCustomerName($data),
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
     * @param mixed $data
     * @return string
     */
    private function getCustomerName($data): string
    {
        if (!is_object($data)) {
            return '';
        }
        if (
            !empty($data->billingContact) &&
            !empty($data->billingContact->givenName) &&
            !empty($data->billingContact->familyName)
        ) {
            return  $data->billingContact->givenName . ' ' . $data->billingContact->familyName;
        }
        return '';
    }
}
