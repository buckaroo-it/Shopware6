<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\SepaDirectDebit;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SepaDirectDebitPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = SepaDirectDebit::class;

    /**
     * Get parameters for specific payment method
     *
     * @return array<mixed>
     */
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        if ($dataBag->has('buckarooSepaDirectDebitIBAN')
            && $dataBag->has('buckarooSepaDirectDebitCustomer')
        ) {
            return [
                'iban' => $dataBag->get('buckarooSepaDirectDebitIBAN'),
                'customer' => [
                    'name' => $dataBag->get('buckarooSepaDirectDebitCustomer'),
                ],
            ];
        }

        return [];
    }
}
