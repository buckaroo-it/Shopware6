<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Events;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;

class PushPaymentStateChangeEvent implements ShopwareSalesChannelEvent
{
    protected SalesChannelContext $salesChannelContext;

    protected Request $request;

    protected $state;

    public function __construct(
        Request $request,
        SalesChannelContext $salesChannelContext,
        string $state
    ) {
        $this->request = $request;
        $this->salesChannelContext = $salesChannelContext;
        $this->state = $state;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getContext(): Context
    {
        return $this->salesChannelContext->getContext();
    }

    public function getState(): string
    {
        return $this->state;
    }
}
