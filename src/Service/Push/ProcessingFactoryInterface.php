<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push;

use Buckaroo\Shopware6\Service\Push\Processors\StatusProcessorInterface;

interface ProcessingFactoryInterface
{
    public function get(Request $request): ?StatusProcessorInterface;
}
