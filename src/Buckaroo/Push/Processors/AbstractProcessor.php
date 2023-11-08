<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\RequestStatus;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

abstract class AbstractProcessor implements StatusProcessorInterface
{
    private const ACTIONS = [
        RequestStatus::SUCCESS => "onSuccess",
        RequestStatus::PENDING => "onPending",
        RequestStatus::FAILED => "onFailed",
        RequestStatus::CANCELLED => "onCancel",
    ];

    /**
     * Processor type
     *
     * @var string
     */
    private $type = 'unknown';

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public function process(ProcessingStateInterface $state): void
    {
        $status = $state->getRequest()->getStatus();
        $state->setType($this->type);
        if (array_key_exists($status, self::ACTIONS)) {
            $method = self::ACTIONS[$status];
            $this->{$method}($state);
        }
    }

    public function onSuccess(ProcessingStateInterface $state): void
    {
    }

    public function onFailed(ProcessingStateInterface $state): void
    {
    }

    public function onCancel(ProcessingStateInterface $state): void
    {
    }

    public function onProcessing(ProcessingStateInterface $state): void
    {
    }

    protected function setStatus(ProcessingStateInterface $state, ?string $status = null): void
    {
        if ($status === null) {
            $status = $state->getRequest()->getStatus();
        }
        $state->setStatus($status);
    }
}
