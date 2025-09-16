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
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Checkout\Payment\PaymentProcessor;

class OrderService
{
    protected SalesChannelContext $salesChannelContext;

    protected OrderPersisterInterface $orderPersister;

    protected EntityRepository $orderRepository;

    protected EntityRepository $orderAddressRepository;

    protected EventDispatcherInterface $eventDispatcher;

    protected PaymentProcessor $paymentProcessor;

    protected LoggerInterface $logger;

    public function __construct(
        OrderPersisterInterface $orderPersister,
        EntityRepository $orderRepository,
        EventDispatcherInterface $eventDispatcher,
        PaymentProcessor $paymentProcessor,
        EntityRepository $orderAddressRepository,
        LoggerInterface $logger
    ) {
        $this->orderPersister = $orderPersister;
        $this->orderRepository = $orderRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->paymentProcessor = $paymentProcessor;
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

        $order = $this->getOrderById($orderId, ['transactions', 'transactions.paymentMethod']);
        if (!$order instanceof OrderEntity) {
            return null;
        }

        $transactions = $order->getTransactions();
        $transaction = $transactions !== null ? $transactions->last() : null; // or first(), depending on your setup
        $paymentMethodId = $transaction?->getPaymentMethod()?->getId();
        $paymentMethodName = $transaction?->getPaymentMethod()?->getName();

        try {
            $request = new Request([], $data->all());

            $response = $this->paymentProcessor->pay(
                $order->getId(),
                $request,
                $this->salesChannelContext,
                $finishUrl,
                $errorUrl
            );

            return $response instanceof RedirectResponse ? $response->getTargetUrl() : null;
        } catch (\Throwable $e) {
            // Log the error with context for debugging without exposing to users
            $this->logger->error('Payment processing failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'paymentMethodId' => $paymentMethodId ?? 'unknown',
                'paymentMethodName' => $paymentMethodName ?? 'unknown'
            ]);
            
            // Return null to indicate payment failure - let calling code handle the response
            return null;
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
        if ($context === null) {
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
        if ($data->get('idealFastCheckoutInfo')) {
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
