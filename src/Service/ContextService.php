<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

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
     */
    public function getStoreName(SalesChannelContext $salesChannelContext): ?string
    {
        return $salesChannelContext->getSalesChannel()->getName();
    }

    /**
     * Get country code
     */
    public function getCountryCode(SalesChannelContext $salesChannelContext): ?string
    {
        /** @var CountryEntity|null */
        $country = $this->countryRepository->search(
            new Criteria([$salesChannelContext->getSalesChannel()->getCountryId()]),
            $salesChannelContext->getContext()
        )->getEntities()->first();

        if ($country !== null) {
            return $country->getIso();
        }

        return null;
    }

    /**
     * Get currency code
     */
    public function getCurrencyCode(SalesChannelContext $salesChannelContext): string
    {
        return $salesChannelContext->getCurrency()->getIsoCode();
    }
}
