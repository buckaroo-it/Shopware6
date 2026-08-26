<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\BuckarooLanguageResolver;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

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

    /**
     * Resolve the Buckaroo culture code (ex. "nl-NL") for the current payment,
     * based on the general "language" plugin setting.
     */
    protected function resolveCulture(
        SalesChannelContext $salesChannelContext,
        ?Request $request = null,
        ?OrderEntity $order = null
    ): string {
        $resolver = $this->asyncPaymentService->getLanguageResolver();
        if ($resolver === null) {
            return BuckarooLanguageResolver::FALLBACK_CULTURE;
        }

        return $resolver->resolveLanguage($salesChannelContext, $request, $order);
    }

    /**
     * Extract context token from data bag
     */
    protected function getContextTokenFromDataBag(RequestDataBag $dataBag): string
    {
        $contextToken = $dataBag->get('sw-context-token', '');
        if (empty($contextToken) || !is_string($contextToken)) {
            return '';
        }
        return $contextToken;
    }
}
