<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

if (class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class)) {
    class IdealQrPaymentHandler extends \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler
    {
        use PaymentHandlerModern;
        
        public function handleResponse(
            ClientResponseInterface $response,
            OrderTransactionEntity $orderTransaction,
            OrderEntity $order,
            RequestDataBag $dataBag,
            SalesChannelContext $salesChannelContext,
            string $paymentCode
        ): RedirectResponse {
            return $this->handleResponseModern($response, $orderTransaction, $order, $dataBag, $salesChannelContext, $paymentCode);
        }
    }
    return;
}

if (interface_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface::class) && !class_exists(\Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler::class)) {
    class IdealQrPaymentHandler implements \Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface
    {
        use PaymentHandlerLegacy;
        
        public function handleResponse(
            ClientResponseInterface $response,
            \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction,
            RequestDataBag $dataBag,
            SalesChannelContext $salesChannelContext,
            string $paymentCode
        ): RedirectResponse {
            return $this->handleResponseLegacy($response, $transaction, $dataBag, $salesChannelContext, $paymentCode);
        }
    }
}


