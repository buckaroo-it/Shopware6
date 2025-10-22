<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Events;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

class AfterPaymentRequestEvent implements ShopwareSalesChannelEvent
{

    protected PaymentTransactionStruct $transaction;

    protected RequestDataBag $dataBag;

    protected SalesChannelContext $salesChannelContext;

    protected ClientResponseInterface $response;

    protected string $paymentCode;

    public function __construct(
        PaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $context,
        ClientResponseInterface $response,
        string $paymentCode
    ) {
        $this->transaction = $transaction;
        $this->dataBag = $dataBag;
        $this->salesChannelContext = $context;
        $this->response = $response;
        $this->paymentCode = $paymentCode;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getContext(): Context
    {
        return $this->salesChannelContext->getContext();
    }

    public function getAsyncPaymentTransaction(): PaymentTransactionStruct
    {
        return $this->transaction;
    }

    public function getRequestDataBag(): RequestDataBag
    {
        return $this->dataBag;
    }

    public function getResponse(): ClientResponseInterface
    {
        return $this->response;
    }

    public function getPaymentCode(): string
    {
        return $this->paymentCode;
    }
}
