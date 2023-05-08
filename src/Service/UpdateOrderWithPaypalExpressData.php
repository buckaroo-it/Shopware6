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

    public function __construct(
        EntityRepository $orderAddressRepository,
        EntityRepository $orderCustomerRepository
    ) {
        $this->orderAddressRepository = $orderAddressRepository;
        $this->orderCustomerRepository = $orderCustomerRepository;
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

        $this->updateCustomerEmail(
            $paypalData,
            $order,
            $saleChannelContext
        );
    }


    /**
     * Update paypal express customer with email
     *
     * @param DataBag $paypalData
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    protected function updateCustomerEmail(
        DataBag $paypalData,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext
    ) {

        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'orderId',
                $order->getId()
            )
        );

        $customer = $this->orderCustomerRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->first();

        if ($customer === null) {
            return;
        }

        $this->orderCustomerRepository->update(
            [[
                "id" => $customer->getId(),
                "email" => $paypalData->get('payeremail')
            ]],
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

            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity */
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
        $this->orderAddressRepository->update(
            [[
                'id' => $addressId,
                'firstName' => $data->get('payerfirstname', 'Unknown'),
                'lastName' => $data->get('payerlastname', 'Paypal Express'),
                'street' =>  $data->get('address_line_1', 'Unknown'),
            ]],
            $salesChannelContext->getContext()
        );
    }
}
