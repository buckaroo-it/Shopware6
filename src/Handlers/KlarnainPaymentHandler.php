<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Klarnain;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class KlarnainPaymentHandler extends KlarnaPaymentHandler
{
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $buckarooKey
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $buckarooKey = null,
        string $type = null,
        string $version = null,
        array $gatewayInfo = []
    ): RedirectResponse{

        $additional = [];
        $latestKey  = 1;
        $order      = $transaction->getOrder();

        $paymentMethod = new Klarnain();
        $additional    = $this->getArticleTotalData($order, $additional, $latestKey, $paymentMethod->getBuckarooKey());
        // $additional = $this->getArticleData($order, $additional, $latestKey);
        // $additional = $this->getBuckarooFee($order, $additional, $latestKey);
        $additional = $this->getAddressArray($order, $additional, $latestKey, $salesChannelContext, $dataBag, $paymentMethod);

        $gatewayInfo = [
            'additional' => $additional,
        ];

        return AsyncPaymentHandler::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentMethod->getBuckarooKey(),
            $paymentMethod->getType(),
            $paymentMethod->getVersion(),
            $gatewayInfo
        );
    }
}
