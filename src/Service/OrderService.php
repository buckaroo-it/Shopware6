<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;

class OrderService
{
    /**
     * Same key format as Shopware's own cart lock, so an express order and a
     * regular checkout of the same cart cannot run at the same time.
     */
    private const CART_LOCK_PREFIX = 'cart-lock';

    private const CART_LOCK_TTL = 30;

    protected SalesChannelContext $salesChannelContext;

    protected OrderPersisterInterface $orderPersister;

    protected EntityRepository $orderRepository;

    protected EntityRepository $orderAddressRepository;

    protected EventDispatcherInterface $eventDispatcher;

    protected ?object $paymentService = null; // Can be PaymentProcessor or PaymentService

    protected LoggerInterface $logger;

    private PaymentServiceFactory $paymentServiceFactory;

    public function __construct(
        OrderPersisterInterface $orderPersister,
        EntityRepository $orderRepository,
        EventDispatcherInterface $eventDispatcher,
        PaymentServiceFactory $paymentServiceFactory,
        EntityRepository $orderAddressRepository,
        LoggerInterface $logger,
        private readonly CartCalculator $cartCalculator,
        private readonly AbstractCartPersister $cartPersister,
        private readonly LockFactory $lockFactory
    ) {
        $this->orderPersister = $orderPersister;
        $this->orderRepository = $orderRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->paymentServiceFactory = $paymentServiceFactory;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->logger = $logger;
    }

    public function place(OrderEntity $orderEntity, RequestDataBag $data): ?string
    {
        $this->validateSaleChannelContext();
        $this->eventDispatcher->dispatch(
            new CheckoutOrderPlacedEvent(
                $this->salesChannelContext,
                $orderEntity,
                new MailRecipientStruct([
                    $orderEntity->getOrderCustomer()?->getEmail() ?? '' => $orderEntity->getOrderCustomer()?->getFirstName() ?? '',
                ])
            )
        );

        return $this->doPayment($orderEntity->getId(), $data);
    }

    public function doPayment(string $orderId, RequestDataBag $data): ?string
    {
        $urls = $this->getCheckoutUrls($orderId, $data);
        $finishUrl = $urls['finishUrl'] ?? null;
        $errorUrl = $urls['errorUrl'] ?? null;

        $this->logger->info('[GooglePay][doPayment] START', [
            'orderId' => $orderId,
            'finishUrl' => $finishUrl,
            'errorUrl' => $errorUrl,
            'dataBagKeys' => array_keys($data->all()),
        ]);

        $order = $this->getOrderById($orderId, [
            'transactions',
            'transactions.paymentMethod',
            'currency',
        ]);
        if (!$order instanceof OrderEntity) {
            $this->logger->error('[GooglePay][doPayment] Could not load order entity', ['orderId' => $orderId]);

            return null;
        }

        $this->logger->info('[GooglePay][doPayment] Order loaded', [
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'amount' => $order->getAmountTotal(),
            'hasCurrency' => $order->getCurrency() !== null,
            'currencyCode' => $order->getCurrency()?->getIsoCode() ?? 'NULL',
        ]);

        $transactions = $order->getTransactions();
        $transaction = $transactions !== null ? $transactions->last() : null;
        $paymentMethodId = $transaction?->getPaymentMethod()?->getId();
        $paymentMethodName = $transaction?->getPaymentMethod()?->getName();

        $this->logger->info('[GooglePay][doPayment] Transaction info', [
            'transactionId' => $transaction?->getId() ?? 'NULL',
            'paymentMethodId' => $paymentMethodId ?? 'NULL',
            'paymentMethodName' => $paymentMethodName ?? 'NULL',
        ]);

        try {
            $this->ensurePaymentServiceResolved();
            if (!is_object($this->paymentService)) {
                throw new \RuntimeException('Payment service is not available');
            }

            $paymentServiceClass = get_class($this->paymentService);
            $hasPay = method_exists($this->paymentService, 'pay');
            $hasHandlePaymentByOrder = method_exists($this->paymentService, 'handlePaymentByOrder');

            $this->logger->info('[GooglePay][doPayment] Payment service resolved', [
                'serviceClass' => $paymentServiceClass,
                'hasPay' => $hasPay,
                'hasHandlePaymentByOrder' => $hasHandlePaymentByOrder,
            ]);

            // Include the sales-channel context token so that PaymentHandlerSimple
            // can reconstruct the correct SalesChannelContext (with logged-in customer)
            // when it calls getSalesChannelContext() inside its pay() method.
            $request = new Request([], $data->all(), [], [], [], [
                'HTTP_SW_CONTEXT_TOKEN' => $this->salesChannelContext->getToken(),
            ]);

            if (method_exists($this->paymentService, 'pay')) {
                // PaymentProcessor API (Shopware 6.7+).
                // Depending on the exact 6.7.x version, pay() is either void or ?RedirectResponse.
                $this->logger->info('[GooglePay][doPayment] Calling PaymentProcessor::pay()');

                $response = $this->paymentService->pay(
                    $order->getId(),
                    $request,
                    $this->salesChannelContext,
                    $finishUrl,
                    $errorUrl
                );

                $responseType = $response === null ? 'null' : get_class($response);
                $targetUrl = $response instanceof RedirectResponse ? $response->getTargetUrl() : null;

                $this->logger->info('[GooglePay][doPayment] PaymentProcessor::pay() returned', [
                    'responseType' => $responseType,
                    'targetUrl' => $targetUrl,
                    'finishUrl' => $finishUrl,
                ]);

                if ($response instanceof RedirectResponse) {
                    return $response->getTargetUrl();
                }

                // void / null return — use the finishUrl we supplied to the processor.
                return $finishUrl;
            } elseif (method_exists($this->paymentService, 'handlePaymentByOrder')) {
                // PaymentService API (Shopware 6.5-6.6) — returns a RedirectResponse.
                $this->logger->info('[GooglePay][doPayment] Calling PaymentService::handlePaymentByOrder()');

                $response = $this->paymentService->handlePaymentByOrder(
                    $order->getId(),
                    $data,
                    $this->salesChannelContext,
                    $finishUrl,
                    $errorUrl
                );

                $targetUrl = $response instanceof RedirectResponse ? $response->getTargetUrl() : $finishUrl;
                $this->logger->info('[GooglePay][doPayment] handlePaymentByOrder returned', [
                    'isRedirect' => $response instanceof RedirectResponse,
                    'targetUrl' => $targetUrl,
                ]);

                return $targetUrl;
            }
            throw new \RuntimeException('Unknown payment service type: ' . $paymentServiceClass);
        } catch (\Throwable $e) {
            $this->logger->error('[GooglePay][doPayment] EXCEPTION — payment processing failed', [
                'orderId' => $orderId,
                'exceptionClass' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'paymentMethodId' => $paymentMethodId ?? 'unknown',
                'paymentMethodName' => $paymentMethodName ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Convert a cart into an order for the express checkout flows.
     *
     * A cart loaded from storage carries no calculation errors, so the cart is
     * always recalculated here under the current sales channel context (currency,
     * rules, payment and shipping method) right before it is persisted, and only
     * persisted when the recalculated cart has no blocking errors. The cart is
     * locked while this happens and removed once the order exists, so the same
     * cart cannot be turned into a second order.
     */
    public function persist(Cart $cart): ?OrderEntity
    {
        $this->validateSaleChannelContext();
        $context = $this->salesChannelContext;
        $token = $cart->getToken();

        $lock = $this->lockFactory->createLock(self::CART_LOCK_PREFIX . $token, self::CART_LOCK_TTL);
        if (!$lock->acquire()) {
            throw new CreateCartException('Cart is already being processed');
        }

        try {
            $this->assertCartIsStored($token, $context);

            $calculatedCart = $this->cartCalculator->calculate($cart, $context);
            if ($calculatedCart->getErrors()->blockOrder()) {
                throw CartException::invalidCart($calculatedCart->getErrors());
            }

            $this->validatePayment($calculatedCart, $context);

            $orderId = $this->orderPersister->persist($calculatedCart, $context);

            $this->deleteCart($token, $context);
        } finally {
            $lock->release();
        }

        return $this->getOrderById($orderId);
    }

    public function getOrderById(
        string $orderId,
        array $associations = ['lineItems'],
        ?Context $context = null
    ): ?OrderEntity {
        if (!$context instanceof Context) {
            $this->validateSaleChannelContext();
            $context = $this->salesChannelContext->getContext();
        }

        $criteria = (new Criteria([$orderId]))
            ->addAssociations($associations);

        if (in_array('transactions', $associations, true)) {
            $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));
        }

        $entity = $this->orderRepository->search(
            $criteria,
            $context
        )->getEntities()->first();

        return $entity instanceof OrderEntity ? $entity : null;
    }

    public function setSaleChannelContext(SalesChannelContext $salesChannelContext): self
    {
        $this->salesChannelContext = $salesChannelContext;

        return $this;
    }

    public function updateOrderAddresses(
        OrderEntity $order,
        array $billingData,
        array $shippingData,
        string $countryId,
        Context $context
    ): void {
        $billingAddress = $order->getAddresses()?->get($order->getBillingAddressId());
        $shippingAddress = $order->getDeliveries()?->first()?->getShippingOrderAddress();

        $updates = [];

        if ($billingAddress) {
            $updates[] = [
                'id' => $billingAddress->getId(),
                'versionId' => $order->getVersionId(),
                'firstName' => $billingData['firstName'],
                'lastName' => $billingData['lastName'],
                'street' => $billingData['street'],
                'zipcode' => $billingData['zipcode'],
                'city' => $billingData['city'],
                'company' => $billingData['company'],
                'countryId' => $countryId,
            ];
        }

        if ($shippingAddress) {
            $updates[] = [
                'id' => $shippingAddress->getId(),
                'versionId' => $order->getVersionId(),
                'firstName' => $shippingData['firstName'],
                'lastName' => $shippingData['lastName'],
                'street' => $shippingData['street'],
                'zipcode' => $shippingData['zipcode'],
                'city' => $shippingData['city'],
                'company' => $shippingData['company'],
                'countryId' => $countryId,
            ];
        }

        if (!empty($updates)) {
            $this->orderAddressRepository->update($updates, $context);
        }
    }

    private function ensurePaymentServiceResolved(): void
    {
        if ($this->paymentService === null) {
            $this->paymentService = $this->paymentServiceFactory->getPaymentService();
        }
    }

    /**
     * The cart must still be stored: once it has been converted into an order
     * it is deleted, so a repeated request for the same cart is refused.
     */
    private function assertCartIsStored(string $token, SalesChannelContext $context): void
    {
        try {
            $this->cartPersister->load($token, $context);
        } catch (\Throwable $th) {
            throw new CreateCartException('Cart is no longer available', 0, $th);
        }
    }

    /**
     * Run the payment handler validation the way Shopware's order route does.
     * PaymentProcessor::validate() exists from Shopware 6.6.x on; the 6.5/6.6
     * PaymentService has no equivalent step for asynchronous handlers.
     */
    private function validatePayment(Cart $cart, SalesChannelContext $context): void
    {
        $this->ensurePaymentServiceResolved();
        if (is_object($this->paymentService) && method_exists($this->paymentService, 'validate')) {
            $this->paymentService->validate($cart, new RequestDataBag(), $context);
        }
    }

    /**
     * The order exists at this point; a failed cleanup must not fail it.
     */
    private function deleteCart(string $token, SalesChannelContext $context): void
    {
        try {
            $this->cartPersister->delete($token, $context);
        } catch (\Throwable $th) {
            $this->logger->warning('[OrderService] could not delete cart after order: ' . $th->getMessage());
        }
    }

    private function validateSaleChannelContext(): void
    {
        if (!isset($this->salesChannelContext)) {
            throw new CreateCartException('SaleChannelContext is required');
        }
    }

    private function getCheckoutUrls(string $orderId, RequestDataBag $data): ?array
    {
        // Express-checkout flows (iDEAL, Google Pay, Apple Pay, PayPal Express) need explicit
        // finish/error URLs so Shopware's PaymentProcessor can build the transaction return
        // URL correctly.
        if (
            $data->get('idealFastCheckoutInfo')
            || $data->get('googlePayInfo')
            || $data->get('applePayInfo')
            || $data->get('paypalExpressInfo')
        ) {
            return [
                'finishUrl' => '/checkout/finish?orderId=' . $orderId,
                'errorUrl' => '/account/order/edit/' . $orderId,
            ];
        }

        return null;
    }
}
