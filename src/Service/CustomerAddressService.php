<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Buckaroo\Shopware6\Service\Exceptions\CreateCustomerAddressException;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CustomerAddressService
{
    public EntityRepository $customerAddressRepository;

    protected SalesChannelContext $salesChannelContext;

    protected EntityRepository $countryRepository;

    public function __construct(
        EntityRepository $customerAddressRepository,
        EntityRepository $countryRepository
    ) {
        $this->customerAddressRepository = $customerAddressRepository;
        $this->countryRepository = $countryRepository;
    }

    /**
     * Create address from data bag for customer
     */
    public function create(DataBag $data, string $customerId, string $salutationId): ?CustomerAddressEntity
    {
        $addressId = Uuid::randomHex();

        $this->customerAddressRepository->create(
            [$this->formatedAddressData($data, $customerId, $addressId, $salutationId)],
            $this->salesChannelContext->getContext()
        );

        /** @var CustomerAddressEntity|null */
        return $this->customerAddressRepository->search(
            (new Criteria([$addressId]))->addAssociation('country'),
            $this->salesChannelContext->getContext()
        )->getEntities()->first();
    }

    /**
     * @return array<mixed>
     */
    public function formatedAddressData(
        DataBag $data,
        string $customerId,
        string $addressId,
        string $salutationId
    ): array {
        return [
            'id' => $addressId,
            'customerId' => $customerId,
            'salutationId' => $salutationId,
            'countryId' => $this->getCountryId($data->get('country_code')),
            'firstName' => $data->get('first_name', 'Unknown'),
            'lastName' => $data->get('last_name', 'Customer - Buckaroo Payments'),
            'zipcode' => $data->get('postal_code'),
            'city' => $data->get('city'),
            'street' => $data->get('street', 'Unknown'),
            'customFields' => [
                'buckarooAddress' => true,
            ],
        ];
    }

    /**
     * Set salesChannelContext
     *
     * @return self
     */
    public function setSaleChannelContext(SalesChannelContext $salesChannelContext)
    {
        $this->salesChannelContext = $salesChannelContext;

        return $this;
    }

    /**
     * Get country from iso code
     *
     * @param mixed $iso
     */
    protected function getCountryId($iso): string
    {
        if (!is_scalar($iso)) {
            $iso = '';
        }

        $this->validateSaleChannelContext();
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('iso', (string) $iso));

        /** @var \Shopware\Core\System\Country\CountryEntity|null */
        $country = $this->countryRepository->search(
            $criteria,
            $this->salesChannelContext->getContext()
        )->getEntities()->first();

        if ($country === null) {
            throw new CreateCustomerAddressException('Cannot create address, cannot find country');
        }

        return $country->getId();
    }

    /**
     * Validate saleChannelContext
     *
     * @return void
     */
    private function validateSaleChannelContext()
    {
        if (!isset($this->salesChannelContext)) {
            throw new CreateCartException('SaleChannelContext is required');
        }
    }
}
