<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Buckaroo\Shopware6\Service\CustomerAddressService;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Buckaroo\Shopware6\Service\Exceptions\CreateCustomerException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;

class CustomerService
{
    /**
     * @var \Buckaroo\Shopware6\Service\CustomerAddressService
     */
    protected $customerAddressService;

    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $customerRepository;

    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $salutationRepository;

    /**
     * @var SalesChannelContext
     */
    protected $salesChannelContext;

    /**
     * @var \Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer
     */
    protected SalesChannelContextRestorer $restorer;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(
        CustomerAddressService $customerAddressService,
        EntityRepository $customerRepository,
        EntityRepository $salutationRepository,
        SalesChannelContextRestorer $restorer,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->customerAddressService = $customerAddressService;
        $this->customerRepository = $customerRepository;
        $this->salutationRepository = $salutationRepository;
        $this->restorer = $restorer;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Get or create user with shippinh address
     *
     * @param DataBag $addressData
     *
     * @return CustomerEntity
     */
    public function get(DataBag $addressData): CustomerEntity
    {
        $this->validateSaleChannelContext();

        $customer = $this->salesChannelContext->getCustomer();

        if ($customer !== null) {
            $addressData->add(
                [
                    'first_name' => $customer->getFirstName(),
                    'last_name' => $customer->getLastName()
                ]
            );

            $address = $this->createAddress($addressData, $customer);
            if ($address !== null) {
                $customer->setActiveShippingAddress($address);
            }
            return $customer;
        }

        return $this->create($addressData);
    }

    /**
     * Create customer and address
     *
     * @param DataBag $data
     *
     * @return \Shopware\Core\Checkout\Customer\CustomerEntity
     */
    protected function create(DataBag $data): CustomerEntity
    {
        $this->validateSaleChannelContext();
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();
        $salutationId = $this->getSalutationId();
        $address = $this->getAddressData($data, $customerId, $addressId, $salutationId);

        $customer = [
            'id' => $customerId,
            'customerNumber' => $data->get('paymentToken', bin2hex(random_bytes(16))),
            'salesChannelId' => $this->salesChannelContext->getSalesChannel()->getId(),
            'languageId' => $this->salesChannelContext->getContext()->getLanguageId(),
            'groupId' => $this->salesChannelContext->getCurrentCustomerGroup()->getId(),
            'defaultPaymentMethodId' => $this->salesChannelContext->getPaymentMethod()->getId(),
            'defaultShippingAddressId' => $addressId,
            'defaultBillingAddressId' => $addressId,
            'salutationId' => $salutationId,
            'firstName' => $data->get('first_name', 'Unknown'),
            'lastName' => $data->get('last_name', 'Customer - Buckaroo Payments'),
            'email' => $data->get('email', 'no-replay@example.com'),
            'active' => true,
            'guest' =>  true,
            'firstLogin' => new \DateTimeImmutable(),
            'addresses' => [$address],
        ];
        $this->customerRepository->create(
            [$customer],
            $this->salesChannelContext->getContext()
        );

        $customer = $this->getCustomerById($customerId);
        $this->loginCreatedCustomer($customer);
        return $customer;
    }

    /**
     * Login customer
     *
     * @param CustomerEntity $customer
     *
     * @return void
     */
    protected function loginCreatedCustomer(CustomerEntity $customer): void
    {
        $context = $this->restorer->restoreByCustomer($customer->getId(), $this->salesChannelContext->getContext());
        $this->eventDispatcher->dispatch(
            new CustomerLoginEvent($context, $customer, $context->getToken())
        );
    }

    public function createAddress(DataBag $data, CustomerEntity $customer): ?CustomerAddressEntity
    {
        $salutationId = $customer->getSalutationId();
        if ($salutationId === null) {
            throw new \UnexpectedValueException('Cannot find validation id');
        }

        return $this->customerAddressService
            ->setSaleChannelContext($this->salesChannelContext)
            ->create($data, $customer->getId(), $salutationId);
    }
    /**
     * Get customer from database based on id
     *
     * @param string $customerId
     *
     * @return \Shopware\Core\Checkout\Customer\CustomerEntity
     */
    public function getCustomerById(string $customerId): CustomerEntity
    {

        $criteria = (new Criteria([$customerId]))
            ->addAssociation('addresses')
            ->addAssociation('activeBillingAddress.country')
            ->addAssociation('defaultBillingAddress.country')
            ->addAssociation('defaultShippingAddress.country')
            ->addAssociation('defaultShippingAddress.countryState')
            ->addAssociation('defaultShippingAddress.salutation');

        /** @var \Shopware\Core\Checkout\Customer\CustomerEntity|null $customer */
        $customer = $this->customerRepository->search(
            $criteria,
            $this->salesChannelContext->getContext()
        )->first();


        if ($customer === null) {
            throw new CreateCustomerException('Cannot create customer');
        }

        $activeShippingAddress = $customer->getActiveShippingAddress();

        if ($activeShippingAddress !== null) {
            /**
             * Update context with the new customer an shipping location
             */
            $this->salesChannelContext->assign([
                'customer' => $customer,
                'shippingLocation' => ShippingLocation::createFromAddress($activeShippingAddress)
            ]);
        }

        return $customer;
    }
    /**
     * Get salutation id
     *
     * @return string
     */
    protected function getSalutationId(): string
    {
        $salutation = $this->salutationRepository->search(
            (new Criteria())->setLimit(1),
            $this->salesChannelContext->getContext()
        )->first();

        /** @var \Shopware\Core\System\Salutation\SalutationEntity|null $salutation */
        if ($salutation === null) {
            throw new CreateCustomerException('Cannot find salutation');
        }

        return $salutation->getId();
    }

    /**
     * Get address data
     *
     * @param DataBag $data
     * @param string $customerId
     * @param string $addressId
     *
     * @return array<mixed>
     */
    protected function getAddressData(
        DataBag $data,
        string $customerId,
        string $addressId,
        string $salutationId
    ): array {
        return $this->customerAddressService
            ->setSaleChannelContext($this->salesChannelContext)
            ->formatedAddressData($data, $customerId, $addressId, $salutationId);
    }

    /**
     * Set salesChannelContext
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return self
     */
    public function setSaleChannelContext(SalesChannelContext $salesChannelContext): self
    {
        $this->salesChannelContext = $salesChannelContext;
        return $this;
    }
    /**
     * Validate saleChannelContext
     *
     * @return void
     */
    private function validateSaleChannelContext(): void
    {
        if (!$this->salesChannelContext instanceof SalesChannelContext) {
            throw new CreateCartException('SaleChannelContext is required');
        }
    }

    /**
     * Update customer entity with custom values
     *
     * @param CustomerEntity $customer
     * @param Context $context
     * @param array $data
     *
     * @return void
     */
    public function updateCustomerData(CustomerEntity $customer, Context $context, array $data): void
    {
        if (count($data) === 0) {
            return;
        }
        $customer->changeCustomFields($data);

        $this->customerRepository->update(
            [[
                'id'           => $customer->getId(),
                'customFields' => $customer->getCustomFields(),
            ]],
            $context
        );
    }
    public function updateDummyCustomerFromPush(CustomerEntity $customer, Request $request, Context $context): void
    {
        $this->salesChannelContext = $this->restorer->restoreByCustomer($customer->getId(), $context);

        // Customer info
        $firstName = urldecode((string)$request->request->get('brq_SERVICE_ideal_ContactDetailsFirstName', $customer->getFirstName()));
        $lastName  = urldecode((string)$request->request->get('brq_SERVICE_ideal_ContactDetailsLastName', $customer->getLastName()));
        $email     = urldecode((string)$request->request->get('brq_SERVICE_ideal_ContactDetailsEmail', $customer->getEmail()));
        $countryCode = $this->resolveCountryCode($request);

        // Billing address
        $billingData = new DataBag([
            'first_name'   => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressFirstName')),
            'last_name'    => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressLastName')),
            'street'       => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressStreet')),
            'postal_code'  => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressPostalCode')),
            'city'         => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressCity')),
            'company'      => urldecode((string)$request->request->get('brq_SERVICE_ideal_InvoiceAddressCompanyName')),
            'country_code' => $countryCode,
        ]);

        // Shipping address
        $shippingData = new DataBag([
            'first_name'   => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressFirstName')),
            'last_name'    => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressLastName')),
            'street'       => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressStreet')),
            'postal_code'  => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressPostalCode')),
            'city'         => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressCity')),
            'company'      => urldecode((string)$request->request->get('brq_SERVICE_ideal_ShippingAddressCompanyName')),
            'country_code' => $countryCode,
        ]);

//        $billingAddress = $customer->getDefaultBillingAddress();
//        $shippingAddress = $customer->getDefaultShippingAddress();

//        if ($billingAddress) {
//            $this->customerAddressService
//                ->setSaleChannelContext($this->salesChannelContext)
//                ->update($billingAddress->getId(), $billingData, $context);
//        } else {
            $billingAddress = $this->createAddress($billingData, $customer);
//        }
//
//        if ($shippingAddress) {
//            $this->customerAddressService
//                ->setSaleChannelContext($this->salesChannelContext)
//                ->update($shippingAddress->getId(), $shippingData, $context);
//        } else {
            $shippingAddress = $this->createAddress($shippingData, $customer);
//        }

        // Update customer profile
        $updateData = [
            'id'        => $customer->getId(),
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'email'     => $email,
        ];

        if ($billingAddress !== null) {
            $updateData['defaultBillingAddressId'] = $billingAddress->getId();
        }

        if ($shippingAddress !== null) {
            $updateData['defaultShippingAddressId'] = $shippingAddress->getId();
        }

        $this->customerRepository->update([$updateData], $context);
    }


    protected function resolveCountryCode(Request $request): string
    {
        $countryName = $request->request->get('brq_SERVICE_ideal_ShippingAddressCountryName');

        $mapping = [
            'Netherlands' => 'NL',
            'Germany'     => 'DE',
            'Belgium'     => 'BE',
        ];

        return $mapping[$countryName] ?? 'NL';
    }

}
