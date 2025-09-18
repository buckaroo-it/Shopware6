<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\WeChatPay;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class WeChatPayPaymentHandler extends PaymentHandlerSimple
{
    protected string $paymentClass = WeChatPay::class;

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
        return [
            'locale' => $this->getLocaleCode(
                $this->asyncPaymentService->getCountry(
                    $this->asyncPaymentService->getBillingAddress($order)
                )->getIso()
            ),
        ];
    }


    private function getLocaleCode(string $country = null): string
    {
        if ($country == 'CN') {
            return 'zh-CN';
        }
        if ($country == 'TW') {
            return 'zh-TW';
        }
        return 'en-US';
    }
}
