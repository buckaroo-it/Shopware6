<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\PaymentMethods\In3;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class In3LogoService
{
    public const DEFAULT_PAYMENT_ICON = 'default_payment_icon';

    protected EntityRepository $mediaRepository;

    protected EntityRepository $paymentMethodRepository;

    public function __construct(
        EntityRepository $mediaRepository,
        EntityRepository $paymentMethodRepository,
    ) {
        $this->mediaRepository = $mediaRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }


    /**
     * Get in3 logos
     *
     * @param Context $context
     *
     * @return array
     */
    public function getLogos(Context $context): array
    {
        $v3Logo = $this->getIn3V3Logo($context);
        $defaultLogo = $this->getDefaultLogo($context);

        $data = [];

        if (count($defaultLogo)) {
            $data[] = $defaultLogo;
        }

        if (count($v3Logo)) {
            $data[] = $v3Logo;
        }
        return $data;
    }

    /**
     * Get formatted default logo for api
     *
     * @param Context $context
     *
     * @return array
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
     *
     * @param Context $context
     *
     * @return MediaEntity|null
     */
    private function getMediaFromPayment(Context $context): ?MediaEntity
    {
        $paymentMethod = new In3();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethod->getPaymentHandler()));
        $criteria->addAssociation('media');

        /** @var PaymentMethodEntity|null */
        $method = $this->paymentMethodRepository->search($criteria, $context)->first();
        if ($method === null) {
            return null;
        }
        return $method->getMedia();
    }

    /**
     * Get new in3 logo from media repository
     *
     * @param Context $context
     *
     * @return array
     */
    private function getIn3V3Logo(Context $context): array
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                'buckaroo-in3-ideal'
            )
        );

        /** @var MediaEntity|null $media */
        $media = $this->mediaRepository->search($criteria, $context)->first();

        if ($media === null) {
            return [];
        }

        return $this->getFormatedMedia($media);
    }

    /**
     * Get formated media for api
     *
     * @param MediaEntity $media
     * @param string|null $code
     *
     * @return array
     */
    private function getFormatedMedia(MediaEntity $media, string $code = null): array
    {
        if ($code === null) {
            $code = $media->getId();
        }

        return [
            "code" => $code,
            "name" => $media->getFileName(),
            "src" => $media->getUrl()
        ];
    }

    /**
     * Get active media logo
     *
     * @param string|null $mediaId
     * @param Context $context
     *
     * @return MediaEntity|null
     */
    public function getActiveLogo($mediaId, Context $context): ?MediaEntity
    {
        if (!is_string($mediaId)) {
            return null;
        }
        if ($mediaId === self::DEFAULT_PAYMENT_ICON) {
            return null;
        }

        return $this->mediaRepository->search(new Criteria([$mediaId]), $context)->first();
    }
}
