<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Payment;

use Buckaroo\Shopware6\Service\PayByBankService;
use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;

class PayByBank implements ConfigInterface
{
    protected PayByBankService $payByBankService;

    public function __construct(PayByBankService $payByBankService)
    {
        $this->payByBankService = $payByBankService;
    }

    public function get(State $state): array
    {
        $customer = $state->getCustomer();
        return [
            'payByBankMode'            => $state->getSetting('paybybankRenderMode'),
            'payByBankIssuers'         => $this->payByBankService->getIssuers($customer),
            'payByBankLogos'           => $this->payByBankService->getIssuerLogos($customer),
            'payByBankActiveIssuer'    => $this->payByBankService->getActiveIssuer($customer)
        ];
    }
}
