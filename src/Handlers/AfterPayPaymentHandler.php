<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\AfterPay;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AfterPayPaymentHandler extends AsyncPaymentHandler
{
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = null,
        array $gatewayInfo = []
    ): RedirectResponse {

        $additional = []; $latestKey = 1;
        $order = $transaction->getOrder();

        $additional = $this->checkoutHelper->getArticleData($order, $additional, $latestKey);
        $additional = $this->checkoutHelper->getAddressArray($order, $additional, $latestKey, $salesChannelContext, $dataBag);

        $paymentMethod = new AfterPay();
        $gatewayInfo = [
            'key' =>  $paymentMethod->getBuckarooKey(),
            'version' =>  $paymentMethod->getVersion(),
            'additional' =>  $additional,
        ];

        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentMethod->getGatewayCode(),
            $paymentMethod->getType(),
            $gatewayInfo
        );
    }
}
