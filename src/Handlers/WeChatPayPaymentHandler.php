<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\WeChatPay;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class WeChatPayPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = WeChatPay::class;

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
        return [
            'locale' => $this->getLocaleCode(
                $transaction->getOrder()
                    ->getBillingAddress()
                    ->getCountry()
                    ->getIso()
            ),
        ];
    }


    private function getLocaleCode($country)
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
