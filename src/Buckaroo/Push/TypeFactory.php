<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

use Buckaroo\Shopware6\Buckaroo\Push\Request;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingFactoryInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

class TypeFactory implements ProcessingFactoryInterface
{
    /**
     * @var ProcessingFactoryInterface[]
     */
    private array $typeFactories;

    private ?StatusProcessorInterface $defaultProcessor;

    public function __construct(array $typeFactories, StatusProcessorInterface $defaultProcessor = null)
    {
        $this->typeFactories = $typeFactories;
        $this->defaultProcessor = $defaultProcessor;
    }

    /**
     * Get processor based on type
     *
     * @param Request $request
     *
     * @return StatusProcessorInterface|null
     */
    public function get(Request $request): ?StatusProcessorInterface
    {
        $factory = $this->typeFactories[$request->getType()] ?? null;

        if ($factory === null) {
            return $this->defaultProcessor;
        }

        return $factory->get($request);
    }

}
