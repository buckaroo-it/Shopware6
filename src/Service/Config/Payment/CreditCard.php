<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Payment;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Handlers\CreditcardPaymentHandler;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;

class CreditCard implements ConfigInterface
{
    public function get(State $state): array
    {
        return [
            'creditcard'           => $this->getCreditCards($state),
            'last_used_creditcard' => $this->getLastUsedCreditCard($state->getCustomer()),
        ];
    }

    private function getLastUsedCreditCard(?CustomerEntity $customer = null)
    {
        $lastUsedCreditcard = 'visa';

        if ($customer !== null) {
            $storedUsedCreditcard = $customer->getCustomFieldsValue(CreditcardPaymentHandler::ISSUER_LABEL);
        }

        if (is_string($lastUsedCreditcard)) {
            $lastUsedCreditcard = $storedUsedCreditcard;
        }

        return $lastUsedCreditcard;
    }

    private function getCreditCards(State $state): array
    {

        $allowedcreditcard = $state->getSetting('allowedcreditcard');

        
        if (!is_array($allowedcreditcard)) {
            return [];
        }

        $creditcard = [];
        foreach ($allowedcreditcard as $code) {
            if (!is_string($code)) {
                continue;
            }

            $creditcard[] = [
                'name' => self::getCardName($code),
                'code' => $code,
            ];
        }



        return $creditcard;
    }

    public static function getCardName(string $code): string
    {
        $names = [
            'mastercard'     => 'MasterCard',
            'visa'           => 'Visa',
            'amex'           => 'American Express',
            'vpay'           => 'VPay',
            'maestro'        => 'Maestro',
            'visaelectron'   => 'Visa Electron',
            'cartebleuevisa' => 'Carte Bleue',
            'cartebancaire'  => 'Carte Bancaire',
            'dankort'        => 'Dankort',
            'nexi'           => 'Nexi',
            'postepay'       => 'PostePay',
        ];

        if (
            isset($names[$code])
        ) {
            return $names[$code];
        }

        return 'Unnamed credit card';
    }
}
