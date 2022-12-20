<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\ApplePay;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ApplePayPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = ApplePay::class;

    /**
     * Get parameters for specific payment method
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array
     */
    protected function getMethodPayload(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        $applePayInfo = $dataBag->get('applePayInfo');

        if (is_string($applePayInfo)) {
            $data = json_decode($applePayInfo);
            return [
                "customerCardName" => $this->getCustomerName($data),
                "paymentData" => $this->getPaymentData($data)
            ];
        }

        return [];
    }

    private function getPaymentData($data): string
    {
        if (!empty($data->token)) {
            return base64_encode(json_encode($data->token));
        }
        return '';
    }
    private function getCustomerName($data): string
    {
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
