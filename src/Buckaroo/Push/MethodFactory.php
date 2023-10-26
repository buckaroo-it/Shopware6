<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

use Buckaroo\Shopware6\Buckaroo\Push\Request;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

class MethodFactory
{
    /**
     * @var StatusProcessorInterface[]
     */
    private array $processors;

    private ?StatusProcessorInterface $defaultProcessor;
 
    public function __construct(array $processors, StatusProcessorInterface $defaultProcessor = null)
    {
        $this->processors = $processors;
        $this->defaultProcessor = $defaultProcessor;
    }

    public function get(Request $request): ?StatusProcessorInterface
    {
        $method = $request->getServiceCode();
        
        if ($method === null) {
            return $this->defaultProcessor;
        }
        return $this->processors[$method] ?? $this->defaultProcessor ?? null;
    }
}
