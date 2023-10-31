<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push\Processors;

use Buckaroo\Shopware6\Buckaroo\Push\ProcessingStateInterface;

interface StatusProcessorInterface
{
    public function process(ProcessingStateInterface $state): void;
    public function onSuccess(ProcessingStateInterface $state): void;
    public function onFailed(ProcessingStateInterface $state): void;
    public function onCancel(ProcessingStateInterface $state): void;
    public function onProcessing(ProcessingStateInterface $state): void;
}
