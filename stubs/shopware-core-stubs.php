<?php

declare(strict_types=1);


namespace Shopware\Core\Checkout\Payment\Cart
{
    if (!class_exists(AsyncPaymentTransactionStruct::class)) {
        class AsyncPaymentTransactionStruct
        {
            public function getOrderTransactionId(): string
            {
                return '';
            }

            public function getReturnUrl(): string
            {
                return '';
            }

            public function getOrder()
            {
                return null;
            }

            public function getOrderTransaction()
            {
                return null;
            }
        }
    }

    if (!class_exists(PaymentTransactionStruct::class)) {
        class PaymentTransactionStruct
        {
            public function getOrderTransactionId(): string
            {
                return '';
            }

            public function getReturnUrl(): string
            {
                return '';
            }
        }
    }
}

namespace Shopware\Core\Checkout\Payment\Cart\PaymentHandler
{
    if (!interface_exists(AsynchronousPaymentHandlerInterface::class)) {
        interface AsynchronousPaymentHandlerInterface
        {
        }
    }

    if (!class_exists(AbstractPaymentHandler::class)) {
        abstract class AbstractPaymentHandler
        {
            abstract public function supports(
                mixed $type,
                string $paymentMethodId,
                \Shopware\Core\Framework\Context $context
            ): bool;
            
            abstract public function pay(
                \Symfony\Component\HttpFoundation\Request $request,
                \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction,
                \Shopware\Core\Framework\Context $context,
                \Shopware\Core\Framework\Struct\Struct|null $validateStruct
            );
            
            abstract public function finalize(
                \Symfony\Component\HttpFoundation\Request $request,
                \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction,
                \Shopware\Core\Framework\Context $context
            ): void;
        }
    }


}

namespace Shopware\Core\Checkout\Payment
{
    if (!interface_exists(PaymentProcessor::class)) {
        interface PaymentProcessor
        {
            public function pay(
                string $orderId,
                \Symfony\Component\HttpFoundation\Request $request,
                \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
                ?string $finishUrl = null,
                ?string $errorUrl = null
            ): ?\Symfony\Component\HttpFoundation\RedirectResponse;
        }
    }
}

namespace Buckaroo\Shopware6\Handlers
{
    if (!class_exists(PaymentHandler::class)) {
        class PaymentHandler extends PaymentHandlerModern
        {
        }
    }
}

namespace Shopware\Core\Checkout\Cart\Event
{
    if (!class_exists(CheckoutOrderPlacedEvent::class)) {
        class CheckoutOrderPlacedEvent
        {
            public function __construct(
                \Shopware\Core\Framework\Context|\Shopware\Core\System\SalesChannel\SalesChannelContext $context,
                \Shopware\Core\Checkout\Order\OrderEntity $order,
                \Shopware\Core\Framework\Event\EventData\MailRecipientStruct|string|null $third = null
            ) {
            }
        }
    }
}
