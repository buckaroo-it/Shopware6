<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Payment;

use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;

class In3 implements ConfigInterface
{
    protected In3LogoService $in3LogoService;

    public function __construct(In3LogoService $in3LogoService)
    {
        $this->in3LogoService = $in3LogoService;
    }

    public function get(State $state): array
    {
        return [
            'in3Logo' => $this->in3LogoService->getActiveLogo(
                $state->getSetting('capayableLogo'),
                $state->getSetting('capayableVersion'),
                $state->getSalesChannel()->getContext()
            )
        ];
    }
}
