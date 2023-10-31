<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\RequestStatus;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

abstract class AbstractProcessor implements StatusProcessorInterface
{
    private const ACTIONS = [
        RequestStatus::STATUS_SUCCESS => "onSuccess",
        RequestStatus::STATUS_PENDING => "onPending",
        RequestStatus::STATUS_FAILED => "onFailed",
        RequestStatus::STATUS_CANCELLED => "onCancel",
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
        if (array_key_exists($status, self::ACTIONS)) {
            $method = self::ACTIONS[$status];
            $this->{$method}($state);
        }
    }

    public function onSuccess(ProcessingStateInterface $state): void
    {
         $state->setSkipped();
    }

    public function onFailed(ProcessingStateInterface $state): void
    {
         $state->setSkipped();
    }

    public function onCancel(ProcessingStateInterface $state): void
    {
         $state->setSkipped();
    }

    public function onProcessing(ProcessingStateInterface $state): void
    {
         $state->setSkipped();
    }
}
