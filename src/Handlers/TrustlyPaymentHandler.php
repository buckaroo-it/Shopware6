<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Trustly;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TrustlyPaymentHandler extends AsyncPaymentHandler
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
    ): RedirectResponse {
        $dataBag = $this->getRequestBag($dataBag);

        $order      = $transaction->getOrder();
        $paymentMethod = new Trustly();
        $gatewayInfo   = [
            'additional' => [$this->getTrustlyData($order, $salesChannelContext, $dataBag)]
        ];

        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentMethod->getBuckarooKey(),
            $paymentMethod->getType(),
            $paymentMethod->getVersion(),
            $gatewayInfo
        );
    }

    public function getTrustlyData($order, $salesChannelContext, $dataBag){
        $address  = $this->checkoutHelper->getBillingAddress($order, $salesChannelContext);
        return [
            $this->checkoutHelper->getRequestParameterRow($address->getFirstName(), 'CustomerFirstName'),
            $this->checkoutHelper->getRequestParameterRow($address->getLastName(), 'CustomerLastName'),
            $this->checkoutHelper->getRequestParameterRow(($this->checkoutHelper->getCountryCode($address)), 'CustomerCountryCode')
        ];
    }
}
