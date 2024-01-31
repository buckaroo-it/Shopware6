<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Common;

use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;

class PaypalExpress implements ConfigInterface
{
    public function get(State $state): array
    {
        return [
            'showPaypalExpress'        => $this->canShow($state),
            'paypalMerchantId'         => $this->getMerchantId($state),
            'websiteKey'               => $state->getSetting('websiteKey'),
        ];
    }

    protected function canShow(State $state): bool
    {
        $locations = $state->getSetting('paypalExpresslocation');
        return is_array($locations) &&
            in_array($state->getPage(), $locations) &&
            $this->getMerchantId($state) != null;
    }

    protected function getMerchantId(State $state): ?string
    {
        $merchantId =  $state->getSetting('paypalExpressmerchantid');
        if ($merchantId !== null && is_scalar($merchantId)) {
            return (string)$merchantId;
        }
        return null;
    }
}
