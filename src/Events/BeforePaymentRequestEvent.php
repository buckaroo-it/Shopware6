<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Events;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\Client;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;

class BeforePaymentRequestEvent implements ShopwareSalesChannelEvent
{

    protected AsyncPaymentTransactionStruct $transaction;

    protected RequestDataBag $dataBag;

    protected SalesChannelContext $salesChannelContext;

    protected Client $client;

    public function __construct(
        AsyncPaymentTransactionStruct $transaction,
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

    public function getAsyncPaymentTransaction(): AsyncPaymentTransactionStruct
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
