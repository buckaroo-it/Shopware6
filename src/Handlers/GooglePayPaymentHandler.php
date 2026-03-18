<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\GooglePay;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class GooglePayPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = GooglePay::class;

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
        $googlePayInfo = $dataBag->get('googlePayInfo');

        $logger = $this->asyncPaymentService->logger;

        if (!is_string($googlePayInfo) || $googlePayInfo === '') {
            $logger->error('GooglePayPaymentHandler: googlePayInfo is missing or not a string. DataBag keys: ' . implode(', ', array_keys($dataBag->all())));
            return [];
        }

        $data = json_decode($googlePayInfo);
        if ($data === false || !is_object($data)) {
            $logger->error('GooglePayPaymentHandler: failed to decode googlePayInfo JSON. Value (truncated): ' . substr($googlePayInfo, 0, 200));
            return [];
        }

        $paymentData = $this->getPaymentData($data);
        if ($paymentData === '') {
            $logger->error('GooglePayPaymentHandler: getPaymentData returned empty string. Decoded data: ' . substr(json_encode($data), 0, 300));
        }

        $logger->info('GooglePayPaymentHandler: getMethodPayload succeeded, paymentData length: ' . strlen($paymentData));

        return [
            'paymentData'      => $paymentData,
            'customerCardName' => $this->getCustomerName($data),
        ];
    }

    /**
     * Extract and base64-encode the Google Pay token
     *
     * @param mixed $data
     * @return string
     */
    private function getPaymentData($data): string
    {
        if (!is_object($data)) {
            return '';
        }

        // Google Pay token lives at paymentMethodData.tokenizationData.token
        $token = $data->paymentMethodData->tokenizationData->token ?? null;

        if ($token === null) {
            return '';
        }

        // Token is already a JSON string from the Google Pay API
        if (is_string($token)) {
            return base64_encode($token);
        }

        $encoded = json_encode($token);
        if ($encoded === false) {
            return '';
        }

        return base64_encode($encoded);
    }

    /**
     * Extract cardholder name from Google Pay payment data
     *
     * @param mixed $data
     * @return string
     */
    private function getCustomerName($data): string
    {
        if (!is_object($data)) {
            return '';
        }

        $name = $data->paymentMethodData->info->cardDetails ?? null;
        if (!empty($name) && is_string($name)) {
            return $name;
        }

        return '';
    }
}
