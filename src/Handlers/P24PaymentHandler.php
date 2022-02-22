<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\P24;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class P24PaymentHandler extends AsyncPaymentHandler
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
        $paymentMethod = new P24();

        $order = $transaction->getOrder();
        $address  = $this->checkoutHelper->getBillingAddress($order, $salesChannelContext);
        $customer = $this->checkoutHelper->getOrderCustomer($order, $salesChannelContext);
        $additional = [
            [
                'Name' => 'CustomerEmail',
                '_' => $customer->getEmail(),
            ],
            [
                'Name' => 'CustomerFirstName',
                '_' => $address->getFirstName(),
            ],
            [
                'Name' => 'CustomerLastName',
                '_' => $address->getLastName(),
            ]
        ];
        $gatewayInfo   = [
            'additional' => [$additional],
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
}
