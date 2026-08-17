<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;

class UpdateOrderWithPaypalExpressData
{
    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $orderAddressRepository;

    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $orderCustomerRepository;

    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $customerRepository;

    public function __construct(
        EntityRepository $orderAddressRepository,
        EntityRepository $orderCustomerRepository,
        EntityRepository $customerRepository
    ) {
        $this->orderAddressRepository = $orderAddressRepository;
        $this->orderCustomerRepository = $orderCustomerRepository;
        $this->customerRepository = $customerRepository;
    }


    public function update(
        ClientResponseInterface $response,
        OrderEntity $order,
        SalesChannelContext $saleChannelContext
    ): void {
        $paypalData = new DataBag($response->getServiceParameters());
        if (!$paypalData->has('payeremail')) {
            return;
        }

        $this->updateAddresses(
            $paypalData,
            $order,
            $saleChannelContext
        );

        $this->updateCustomerDetails(
            $paypalData,
            $order,
            $saleChannelContext
        );
    }


    /**
     * Replace the express placeholder identity ("Unknown Customer - Buckaroo
     * Payments") with the real PayPal payer name and e-mail.
     *
     * @param DataBag $paypalData
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    protected function updateCustomerDetails(
        DataBag $paypalData,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext
    ): void {
        $details = $this->getPayerDetails($paypalData);

        if ($details === []) {
            return;
        }

        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'orderId',
                $order->getId()
            )
        );

        $orderCustomer = $this->orderCustomerRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->first();

        if (!$orderCustomer instanceof OrderCustomerEntity) {
            return;
        }

        $this->orderCustomerRepository->update(
            [array_merge(['id' => $orderCustomer->getId()], $details)],
            $salesChannelContext->getContext()
        );

        $this->updateGuestCustomer(
            $orderCustomer->getCustomerId(),
            $details,
            $salesChannelContext
        );
    }

    /**
     * Payer name/e-mail from the Buckaroo response. Only keys that are actually
     * present are returned, so valid data is never overwritten by a placeholder.
     *
     * @return array<string, string>
     */
    private function getPayerDetails(DataBag $paypalData): array
    {
        $map = [
            'payerfirstname' => 'firstName',
            'payerlastname'  => 'lastName',
            'payeremail'     => 'email',
        ];

        $details = [];
        foreach ($map as $source => $field) {
            $value = $paypalData->get($source);
            if (is_string($value) && trim($value) !== '') {
                $details[$field] = trim($value);
            }
        }

        return $details;
    }

    /**
     * Mirror the payer details onto the guest customer record that was created for
     * the express order, so the customer list does not show the placeholder either.
     * Real accounts are never overwritten with wallet data.
     *
     * @param array<string, string> $details
     */
    private function updateGuestCustomer(
        ?string $customerId,
        array $details,
        SalesChannelContext $salesChannelContext
    ): void {
        if ($customerId === null) {
            return;
        }

        $customer = $this->customerRepository->search(
            new Criteria([$customerId]),
            $salesChannelContext->getContext()
        )->first();

        if (!$customer instanceof CustomerEntity || $customer->getGuest() !== true) {
            return;
        }

        $this->customerRepository->update(
            [array_merge(['id' => $customerId], $details)],
            $salesChannelContext->getContext()
        );
    }

    /**
     * Update paypal express order with first name, last name & address
     *
     * @param DataBag $paypalData
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    protected function updateAddresses(
        DataBag $paypalData,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext
    ) {


        if ($this->orderAddressRepository === null) {
            return;
        }

        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'orderId',
                $order->getId()
            )
        );

        $addresses = $this->orderAddressRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->getEntities();

        if ($addresses !== null) {
            foreach ($addresses as $address) {
                /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity $address */
                $customFields = $address->getCustomFields();
                if ($customFields != null && isset($customFields['buckarooAddress'])) {
                    $this->updateAddress(
                        $address->getId(),
                        $paypalData,
                        $salesChannelContext
                    );
                }
            }
        }
    }
    private function updateAddress(
        string $addressId,
        DataBag $data,
        SalesChannelContext $salesChannelContext
    ): void {
        $map = [
            'payerfirstname' => 'firstName',
            'payerlastname'  => 'lastName',
            'address_line_1' => 'street',
            'postal_code'    => 'zipcode',
            'admin_area_2'   => 'city',
        ];

        $update = ['id' => $addressId];
        foreach ($map as $source => $field) {
            $value = $data->get($source);
            if (is_string($value) && trim($value) !== '') {
                $update[$field] = trim($value);
            }
        }

        if (count($update) === 1) {
            return;
        }

        $this->orderAddressRepository->update([$update], $salesChannelContext->getContext());
    }
}
