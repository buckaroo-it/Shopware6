<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\SepaDirectDebit;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class SepaDirectDebitPaymentHandler extends PaymentHandler
{
    protected string $paymentClass = SepaDirectDebit::class;

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

        if ($dataBag->has('buckarooSepaDirectDebitIBAN') &&
            $dataBag->has('buckarooSepaDirectDebitCustomer')
        ) {
            return [
                'iban'              => $dataBag->get('buckarooSepaDirectDebitIBAN'),
                'customer'      => [
                    'name'          => $dataBag->get('buckarooSepaDirectDebitCustomer')
                ]
            ];
        }
        return [];
    }
}
