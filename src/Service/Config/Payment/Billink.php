<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Payment;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;

class Billink implements ConfigInterface
{
    public function get(State $state): array
    {
        return [
            'billinkBusiness' => $this->getPaymentType($state->getCustomer()),

        ];
    }

    private function getPaymentType(?CustomerEntity $customer = null): string
    {
        if ($customer === null) {
            return 'B2C';
        }

        $billingAddress = $customer->getActiveBillingAddress();

        if ($billingAddress !== null && $billingAddress->getCompany() !== null) {
            return 'B2B';
        }
        return 'B2C';
    }
}
