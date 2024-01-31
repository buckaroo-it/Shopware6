<?php

namespace Buckaroo\Shopware6\Service\Config;

use Buckaroo\Shopware6\Service\Config\State;

interface ConfigInterface
{
    public function get(State $state): array;
}
