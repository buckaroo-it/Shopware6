<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Payment;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;

class Afterpay implements ConfigInterface
{
    public function get(State $state): array
    {
        return [
            'afterpayCustomerType' => $state->getSetting('afterpayCustomerType'),
            'canShowPhone'         => $this->canShowPhone($state->getCustomer())
        ];
    }

    /**
     * Can display phone number if required
     *
     * @param CustomerEntity|null $customer
     *
     * @return boolean
     */
    private function canShowPhone(CustomerEntity $customer = null): bool
    {
        if ($customer === null) {
            return true;
        }
        $billingAddress = $customer->getActiveBillingAddress();
        $shippingAddress = $customer->getActiveShippingAddress();

        return $this->isPhoneEmpty($billingAddress) || $this->isPhoneEmpty($shippingAddress);
    }

    /**
     * Check if phone number is empty
     *
     * @param CustomerAddressEntity|null $address
     *
     * @return boolean
     */
    private function isPhoneEmpty(CustomerAddressEntity $address = null): bool
    {
        if ($address === null) {
            return true;
        }

        return $address->getPhoneNumber() === null || strlen(trim($address->getPhoneNumber())) === 0;
    }
}
