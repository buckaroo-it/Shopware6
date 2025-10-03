<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Trait containing template methods shared between both versions
 * This demonstrates the Template Method pattern
 */
trait PaymentHandlerTemplateMethods
{
    // Template methods for child classes to override
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        // Default implementation - should be overridden by specific payment handlers
        return [];
    }

    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        // Default implementation - should be overridden by specific payment handlers
        return 'pay';
    }
}
