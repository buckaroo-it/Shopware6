<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Events;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\Client;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/**
 * $transaction is typed as `object` rather than the modern PaymentTransactionStruct because
 * that class does not exist on Shopware < 6.7 (this plugin also supports the legacy
 * AsyncPaymentTransactionStruct via PaymentHandlerLegacy). Callers should narrow the type
 * themselves, e.g. via instanceof checks, the same way CheckoutSubscriber::getOrder() does.
 */
class BeforePaymentRequestEvent implements ShopwareSalesChannelEvent
{

    protected object $transaction;

    protected RequestDataBag $dataBag;

    protected SalesChannelContext $salesChannelContext;

    protected Client $client;

    public function __construct(
        object $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $context,
        Client $client
    ) {
        $this->transaction = $transaction;
        $this->dataBag = $dataBag;
        $this->salesChannelContext = $context;
        $this->client = $client;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getContext(): Context
    {
        return $this->salesChannelContext->getContext();
    }

    public function getAsyncPaymentTransaction(): object
    {
        return $this->transaction;
    }

    public function getRequestDataBag(): RequestDataBag
    {
        return $this->dataBag;
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}
