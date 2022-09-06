<?php

declare(strict_types=1);


namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Buckaroo\Shopware6\Service\Exceptions\CreateCustomerAddressException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class CustomerAddressService
{

    /**
     *
     * @var SalesChannelContext
     */
    protected $salesChannelContext;


    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $customerAddressRepository;

    public function __construct(
        EntityRepository $customerAddressRepository,
        EntityRepository $countryRepository
    ) {
        $this->customerAddressRepository = $customerAddressRepository;
        $this->countryRepository = $countryRepository;
    }

    /**
     * Create address from data bag for customer
     *
     * @param DataBag $data
     * @param string $customerId
     *
     * @return \Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity|null
     */
    public function create(DataBag $data, string $customerId, string $salutationId)
    {
        $addressId = Uuid::randomHex();

        $this->customerAddressRepository->create(
            [$this->formatedAddressData($data, $customerId, $addressId, $salutationId)],
            $this->salesChannelContext->getContext()
        );

        return $this->customerAddressRepository->search(
            new Criteria([$addressId]),
            $this->salesChannelContext->getContext()
        )->first();
    }
    public function formatedAddressData(DataBag $data, string $customerId, string $addressId, string $salutationId)
    {
        return [
            'id' => $addressId,
            'customerId' => $customerId,
            'salutationId' => $salutationId,
            'countryId' => $this->getCountryId($data->get('country_code')),
            'firstName' => $data->get('first_name', 'Unknown'),
            'lastName' => $data->get('last_name', 'Paypal Express'),
            'zipcode' => $data->get('postal_code'),
            'city' => $data->get('city'),
            'street' => 'Unknown',
            'customFields' => [
                "paypalAddress" => true
            ]
        ];
    }

    protected function getCountryId(string $iso)
    {
        $this->validateSaleChannelContext();
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('iso', $iso));

        /** @var \Shopware\Core\System\Country\CountryEntity */
        $country = $this->countryRepository->search(
            $criteria,
            $this->salesChannelContext->getContext()
        )->first();

        if ($country === null) {
            throw new CreateCustomerAddressException('Cannot create address, cannot find country');
        }
        return $country->getId();
    }
    /**
     * Set salesChannelContext
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return self
     */
    public function setSaleChannelContext(SalesChannelContext $salesChannelContext)
    {
        $this->salesChannelContext = $salesChannelContext;
        return $this;
    }
    /**
     * Validate saleChannelContext
     *
     * @return void
     */
    private function validateSaleChannelContext()
    {
        if (!$this->salesChannelContext instanceof SalesChannelContext) {
            throw new CreateCartException('SaleChannelContext is required');
        }
    }
}
