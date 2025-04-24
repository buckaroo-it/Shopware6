<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionChainProcessor;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class OrderService
{
    /**
     * @var SalesChannelContext
     */
    protected $salesChannelContext;

    /**
     * @var \Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface
     */
    protected $orderPersister;

    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $orderRepository;

    /**
     * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
     */
    protected $orderAddressRepository;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    protected $eventDispatcher;


    /**
     * @var \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionChainProcessor
     */
    protected $paymentProcessor;

    public function __construct(
        OrderPersisterInterface $orderPersister,
        EntityRepository $orderRepository,
        EventDispatcherInterface $eventDispatcher,
        PaymentTransactionChainProcessor $paymentProcessor,
        EntityRepository $orderAddressRepository
    ) {
        $this->orderPersister = $orderPersister;
        $this->orderRepository = $orderRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->paymentProcessor = $paymentProcessor;
        $this->orderAddressRepository = $orderAddressRepository;
    }

    /**
     * Place request and do payment
     *
     * @param OrderEntity $orderEntity
     * @param RequestDataBag $data
     *
     * @return string|null
     */
    public function place(OrderEntity $orderEntity, RequestDataBag $data)
    {
        $this->validateSaleChannelContext();
        $this->eventDispatcher->dispatch(
            new CheckoutOrderPlacedEvent(
                $this->salesChannelContext->getContext(),
                $orderEntity,
                $this->salesChannelContext->getSalesChannel()->getId()
            )
        );
        return $this->doPayment($orderEntity->getId(), $data);
    }


    /**
     * Do payment request
     *
     * @param string $orderId
     * @param RequestDataBag $data
     *
     * @return string|null
     */
    public function doPayment(string $orderId, RequestDataBag $data): ?string
    {
        $urls = $this->getCheckoutUrls($orderId, $data);

        $finishUrl = $urls['finishUrl'] ?? null;
        $errorUrl = $urls['errorUrl'] ?? null;

        $response = $this->paymentProcessor->process(
            $orderId,
            $data,
            $this->salesChannelContext,
            $finishUrl,
            $errorUrl
        );


        if ($response instanceof RedirectResponse) {
            return $response->getTargetUrl();
        }
        return null;
    }

    /**
     * Create order from cart
     *
     * @param Cart $cart
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity|null
     */
    public function persist(Cart $cart)
    {
        $this->validateSaleChannelContext();
        $orderId = $this->orderPersister->persist($cart, $this->salesChannelContext);
        return $this->getOrderById($orderId);
    }

    /**
     * Get order by id with associations
     *
     * @param string $orderId
     * @param array<string> $associations
     * @param Context|null $context
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity|null
     */
    public function getOrderById(string $orderId, array $associations = ['lineItems'], Context $context = null)
    {
        if ($context === null) {
            $this->validateSaleChannelContext();
            $context = $this->salesChannelContext->getContext();
        }

        $criteria = (new Criteria([$orderId]))
            ->addAssociations($associations)
        ;

        if (in_array('transactions', $associations)) {
            $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));
        }

        /** @var \Shopware\Core\Checkout\Order\OrderEntity|null */
        return $this->orderRepository->search(
            $criteria,
            $context
        )->first();
    }
    /**
     * Set salesChannelContext
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return self
     */
    public function setSaleChannelContext(SalesChannelContext $salesChannelContext)
    {
        $this->salesChannelContext = $salesChannelContext;
        return $this;
    }
    /**
     * Validate saleChannelContext
     *
     * @return void
     */
    private function validateSaleChannelContext()
    {
        if (!$this->salesChannelContext instanceof SalesChannelContext) {
            throw new CreateCartException('SaleChannelContext is required');
        }
    }
    /**
     * Get finish and error URLs for the payment process
     *
     * @param string $orderId
     * @param RequestDataBag $data
     * @return array|null
     */
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
    /**
     * Updates the billing and shipping addresses for a given order.
     *
     * @param OrderEntity $order       The order entity containing address information.
     * @param array       $billingData
     * @param array       $shippingData
     * @param string      $countryId   The ID of the country for both addresses.
     * @param Context     $context     The Shopware context for the operation.
     *
     * @return void
     */
    public function updateOrderAddresses(
        OrderEntity $order,
        array $billingData,
        array $shippingData,
        string $countryId,
        Context $context
    ): void {
        $billingAddress = $order->getAddresses()->get($order->getBillingAddressId());
        $shippingAddress = $order->getDeliveries()->first()?->getShippingOrderAddress();

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
