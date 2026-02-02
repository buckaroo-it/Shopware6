<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Wero;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

class WeroPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Wero::class;

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
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        return [];
    }

    /**
     * Get the action to use for the payment method
     * Supports: Pay, Authorize
     *
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext|null $salesChannelContext
     * @param string|null $paymentCode
     *
     * @return string
     */
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        // Check if authorization is enabled for this sales channel
        if ($salesChannelContext !== null && $this->isAuthorization($salesChannelContext->getSalesChannelId())) {
            return 'Authorize';
        }
        
        // Default to Pay action
        return parent::getMethodAction($dataBag, $salesChannelContext, $paymentCode);
    }

    /**
     * Check if authorization is enabled for the sales channel
     *
     * @param string $salesChannelId
     *
     * @return bool
     */
    private function isAuthorization(string $salesChannelId): bool
    {
        return $this->getSetting('weroAuthorize', $salesChannelId) === true;
    }
}
