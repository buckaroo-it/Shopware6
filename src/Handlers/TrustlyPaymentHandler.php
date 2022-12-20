<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Trustly;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TrustlyPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = Trustly::class;
    
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
        $order = $transaction->getOrder();
        $address = $order->getBillingAddress();
        return [
            'country' => $address->getCountry()->getIso(),
            'customer'      => [
                'firstName' => $address->getFirstName(),
                'lastName' => $address->getLastName()
            ]
        ];
    }
}
