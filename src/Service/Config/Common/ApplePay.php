<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Common;

use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;

class ApplePay implements ConfigInterface
{
    public function get(State $state): array
    {
        return [
            'applePayMerchantId' => $this->getMerchantId($state),
            'showApplePay'       => $state->getSetting('applepayShowProduct') == 1
        ];
    }

    protected function canShow($state): bool
    {
        if ($state->getPage() === 'checkout') {
            return true;
        }

        $page = ucfirst($state->getPage());
        return $state->getSetting('applepayShow' . $page) == 1;
    }

    protected function getMerchantId($state): ?string
    {
        $merchantId =  $state->getSetting('guid');
        if ($merchantId !== null && is_scalar($merchantId)) {
            return (string)$merchantId;
        }
        return null;
    }
}
