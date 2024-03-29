<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Country\CountryEntity;

class ContextService
{
    /**
     * @var EntityRepository
     */
    private $countryRepository;

    public function __construct(
        EntityRepository $countryRepository
    ) {
        $this->countryRepository = $countryRepository;
    }
    /**
     * Get store name
     * @return string|null
     */
    public function getStoreName(SalesChannelContext $salesChannelContext): ?string
    {
        return $salesChannelContext->getSalesChannel()->getName();
    }

    /**
     * Get country code
     * @return string|null
     */
    public function getCountryCode(SalesChannelContext $salesChannelContext): ?string
    {
        /** @var \Shopware\Core\System\Country\CountryEntity */
        $country = $this->countryRepository->search(
            new Criteria([$salesChannelContext->getSalesChannel()->getCountryId()]),
            $salesChannelContext->getContext()
        )->first();

        if ($country !== null) {
            return $country->getIso();
        }
        return null;
    }

    /**
     * Get currency code
     * @return string
     */
    public function getCurrencyCode(SalesChannelContext $salesChannelContext): string
    {
        return $salesChannelContext->getCurrency()->getIsoCode();
    }
}
