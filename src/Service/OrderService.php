<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Buckaroo\Shopware6\Service\PaymentServiceFactory;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class OrderService
{
    protected SalesChannelContext $salesChannelContext;

    protected OrderPersisterInterface $orderPersister;

    protected EntityRepository $orderRepository;

    protected EntityRepository $orderAddressRepository;

    protected EventDispatcherInterface $eventDispatcher;

    protected ?object $paymentService = null; // Can be PaymentProcessor or PaymentService

    protected LoggerInterface $logger;

    public function __construct(
        OrderPersisterInterface $orderPersister,
        EntityRepository $orderRepository,
        EventDispatcherInterface $eventDispatcher,
        PaymentServiceFactory $paymentServiceFactory,
        EntityRepository $orderAddressRepository,
        LoggerInterface $logger
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
                    $orderEntity->getOrderCustomer()?->getEmail() ?? ''
                        => $orderEntity->getOrderCustomer()?->getFirstName() ?? ''
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
            'orderId'   => $orderId,
            'finishUrl' => $finishUrl,
            'errorUrl'  => $errorUrl,
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
            'orderId'       => $order->getId(),
            'orderNumber'   => $order->getOrderNumber(),
            'amount'        => $order->getAmountTotal(),
            'hasCurrency'   => $order->getCurrency() !== null,
            'currencyCode'  => $order->getCurrency()?->getIsoCode() ?? 'NULL',
        ]);

        $transactions = $order->getTransactions();
        $transaction = $transactions !== null ? $transactions->last() : null;
        $paymentMethodId = $transaction?->getPaymentMethod()?->getId();
        $paymentMethodName = $transaction?->getPaymentMethod()?->getName();

        $this->logger->info('[GooglePay][doPayment] Transaction info', [
            'transactionId'     => $transaction?->getId() ?? 'NULL',
            'paymentMethodId'   => $paymentMethodId ?? 'NULL',
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
                'serviceClass'           => $paymentServiceClass,
                'hasPay'                 => $hasPay,
                'hasHandlePaymentByOrder' => $hasHandlePaymentByOrder,
                'contextToken'           => $this->salesChannelContext->getToken(),
            ]);

            // Include the sales-channel context token so that PaymentHandlerSimple
            // can reconstruct the correct SalesChannelContext (with logged-in customer)
            // when it calls getSalesChannelContext() inside its pay() method.
            $request = new Request([], $data->all(), [], [], [], [
                'HTTP_SW_CONTEXT_TOKEN' => $this->salesChannelContext->getToken(),
            ]);

            if ($hasPay) {
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
                    'targetUrl'    => $targetUrl,
                    'finishUrl'    => $finishUrl,
                ]);

                if ($response instanceof RedirectResponse) {
                    return $response->getTargetUrl();
                }

                // void / null return — use the finishUrl we supplied to the processor.
                return $finishUrl;
            } elseif ($hasHandlePaymentByOrder) {
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
                    'targetUrl'  => $targetUrl,
                ]);

                return $targetUrl;
            } else {
                throw new \RuntimeException('Unknown payment service type: ' . $paymentServiceClass);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[GooglePay][doPayment] EXCEPTION — payment processing failed', [
                'orderId'           => $orderId,
                'exceptionClass'    => get_class($e),
                'message'           => $e->getMessage(),
                'file'              => $e->getFile() . ':' . $e->getLine(),
                'paymentMethodId'   => $paymentMethodId ?? 'unknown',
                'paymentMethodName' => $paymentMethodName ?? 'unknown',
                'trace'             => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private PaymentServiceFactory $paymentServiceFactory;

    private function ensurePaymentServiceResolved(): void
    {
        if ($this->paymentService === null) {
            $this->paymentService = $this->paymentServiceFactory->getPaymentService();
        }
    }

    public function persist(Cart $cart): ?OrderEntity
    {
        $this->validateSaleChannelContext();
        $orderId = $this->orderPersister->persist($cart, $this->salesChannelContext);
        return $this->getOrderById($orderId);
    }

    public function getOrderById(
        string $orderId,
        array $associations = ['lineItems'],
        Context $context = null
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
        )->first();
        return $entity instanceof OrderEntity ? $entity : null;
    }

    public function setSaleChannelContext(SalesChannelContext $salesChannelContext): self
    {
        $this->salesChannelContext = $salesChannelContext;
        return $this;
    }

    private function validateSaleChannelContext(): void
    {
        if (!$this->salesChannelContext instanceof SalesChannelContext) {
            throw new CreateCartException('SaleChannelContext is required');
        }
    }

    private function getCheckoutUrls(string $orderId, RequestDataBag $data): ?array
    {
        // Express-checkout flows (iDEAL, Google Pay, Apple Pay) need explicit finish/error
        // URLs so Shopware's PaymentProcessor can build the transaction return URL correctly.
        if (
            $data->get('idealFastCheckoutInfo') ||
            $data->get('googlePayInfo') ||
            $data->get('applePayInfo')
        ) {
            return [
                'finishUrl' => '/checkout/finish?orderId=' . $orderId,
                'errorUrl'  => '/account/order/edit/' . $orderId,
            ];
        }

        return null;
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
}
