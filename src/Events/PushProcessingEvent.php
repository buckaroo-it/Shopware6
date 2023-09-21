<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Events;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;

class PushProcessingEvent implements ShopwareSalesChannelEvent
{

    protected SalesChannelContext $salesChannelContext;

    protected Request $request;

    protected bool $canContinue;

    public function __construct(
        Request $request,
        SalesChannelContext $context,
        bool $canContinue = true
    ) {
        $this->salesChannelContext = $context;
        $this->request = $request;
        $this->canContinue = $canContinue;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getContext(): Context
    {
        return $this->salesChannelContext->getContext();
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function canContinue(): bool
    {
        return $this->canContinue;
    }

    public function setCanContinue(bool $canContinue): self
    {
        $this->canContinue = $canContinue;
        return $this;
    }
}
