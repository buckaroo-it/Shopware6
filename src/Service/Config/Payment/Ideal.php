<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Payment;

use Buckaroo\Shopware6\Service\IdealIssuerService;
use Buckaroo\Shopware6\Service\Config\State;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;

class Ideal implements ConfigInterface
{
    protected IdealIssuerService $idealIssuerService;

    public function __construct(IdealIssuerService $idealIssuerService)
    {
        $this->idealIssuerService = $idealIssuerService;
    }

    public function get(State $state): array
    {
        return [
            'issuers'                  => $this->idealIssuerService->get($state->getSalesChannelId()),
            'ideal_render_mode'        => $this->getIdealRenderMode($state),
            'showIssuers'              => $this->canShowIssuers($state),
        ];
    }

    protected function getIdealRenderMode(State $state): int
    {
        $mode = $state->getSetting('idealRenderMode');

        if ($mode !== null && is_scalar($mode)) {
            return (int)$mode;
        }

        return 0;
    }

    private function canShowIssuers(State $state): bool
    {
        $buckarooKey = $state->getBuckarooKey();
        if ($buckarooKey === null) {
            return true;
        }
        return $state->getSetting($buckarooKey."Showissuers") !== false;
    }
}
