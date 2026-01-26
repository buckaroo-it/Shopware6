<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Framework\Twig;

use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Buckaroo\Shopware6\Service\SettingsService;

class BuckarooTwigExtension extends AbstractExtension
{
    public function __construct(
        private SalesChannelRepository $paymentMethodRepository,
        private SettingsService $settingsService
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'buckaroo_enabled_payment_methods',
                [$this, 'getEnabledPaymentMethods'],
                ['needs_context' => true]
            ),
        ];
    }

    public function getEnabledPaymentMethods(array $twigContext): PaymentMethodCollection
    {
        $context = $twigContext['context'] ?? null;
        if (!$context instanceof SalesChannelContext) {
            return new PaymentMethodCollection();
        }

        $salesChannelId = $context->getSalesChannelId();
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('active', true))
            ->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId))
            ->addAssociation('media');

        $paymentMethods = $this->paymentMethodRepository->search($criteria, $context)->getEntities();
        $enabled = new PaymentMethodCollection();
        
        foreach ($paymentMethods as $paymentMethod) {
            $buckarooKey = $paymentMethod->getTranslated()['customFields']['buckaroo_key'] ?? null;
            
            if (!$buckarooKey
                || !$this->settingsService->getEnabled($buckarooKey, $salesChannelId)
                || !$paymentMethod->getMedia()
            ) {
                continue;
            }
            
            $enabled->add($paymentMethod);
        }
        
        return $enabled;
    }
}
