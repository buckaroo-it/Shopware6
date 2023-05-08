<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Klarnain;
use Buckaroo\Shopware6\Handlers\KlarnaPaymentHandler;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class KlarnainPaymentHandler extends KlarnaPaymentHandler
{
    protected string $paymentClass = Klarnain::class;

    /**
    * Get method action for specific payment method
    *
    * @param RequestDataBag $dataBag
    * @param SalesChannelContext $salesChannelContext
    * @param string $paymentCode
    *
    * @return string
    */
   protected function getMethodAction(
       RequestDataBag $dataBag,
       SalesChannelContext $salesChannelContext,
       string $paymentCode
   ): string {
       return 'payInInstallments';
   }
}
