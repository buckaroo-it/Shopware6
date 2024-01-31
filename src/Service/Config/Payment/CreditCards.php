<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Payment;

use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\Payment\CreditCard;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;

class CreditCards implements ConfigInterface
{
    public function get(State $state): array
    {
        return [
            'creditcards' => $this->getCreditCards($state),
        ];
    }

    private function getCreditCards(State $state): array
    {

        $allowedcreditcard = $state->getSetting('allowedcreditcards');

        if (!is_array($allowedcreditcard)) {
            return [];
        }

        $creditcard = [];
        foreach ($allowedcreditcard as $code) {
            if (!is_string($code)) {
                continue;
            }

            $creditcard[] = [
                'name' => CreditCard::getCardName($code),
                'code' => $code,
            ];
        }

        return $creditcard;
    }
}
