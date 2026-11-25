<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Klarnain;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class KlarnainPaymentHandler extends KlarnaPaymentHandler
{
    public string $paymentClass = Klarnain::class;

    /**
     * Get method action for specific payment method
     */
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        return 'payInInstallments';
    }
}
