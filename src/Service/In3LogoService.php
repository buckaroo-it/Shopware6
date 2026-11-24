<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\PaymentMethods\In3;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class In3LogoService
{
    public const DEFAULT_PAYMENT_ICON = 'default_payment_icon';

    protected EntityRepository $paymentMethodRepository;

    public function __construct(
        EntityRepository $paymentMethodRepository,
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    /**
     * Get in3 logos
     */
    public function getLogos(Context $context): array
    {
        $defaultLogo = $this->getDefaultLogo($context);

        $data = [];

        if (count($defaultLogo)) {
            $data[] = $defaultLogo;
        }

        return $data;
    }

    /**
     * Get active media logo
     */
    public function getActiveLogo(Context $context): ?MediaEntity
    {
        return null;
    }

    /**
     * Get formatted default logo for api
     */
    private function getDefaultLogo(Context $context): array
    {
        $media = $this->getMediaFromPayment($context);

        if ($media === null) {
            return [];
        }

        return $this->getFormatedMedia($media, self::DEFAULT_PAYMENT_ICON);
    }

    /**
     * Get media from in3 payment method
     */
    private function getMediaFromPayment(Context $context): ?MediaEntity
    {
        $paymentMethod = new In3();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethod->getPaymentHandler()));
        $criteria->addAssociation('media');

        /** @var PaymentMethodEntity|null */
        $method = $this->paymentMethodRepository->search($criteria, $context)->getEntities()->first();
        if ($method === null) {
            return null;
        }

        return $method->getMedia();
    }

    /**
     * Get formated media for api
     */
    private function getFormatedMedia(MediaEntity $media, ?string $code = null): array
    {
        if ($code === null) {
            $code = $media->getId();
        }

        return [
            'code' => $code,
            'name' => $media->getFileName(),
            'src' => $media->getUrl(),
        ];
    }
}
