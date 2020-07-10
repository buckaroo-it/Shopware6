<?php
declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Helpers\Config;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPosition;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Checkout\Cart\Price\AmountCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Checkout\Payment\Exception\UnknownPaymentMethodException;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Checkout\Test\Payment\Handler\SyncTestPaymentHandler;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\Framework\Uuid\Exception\InvalidUuidException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\Content\Product\Cart\ProductLineItemFactory;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\CartPersisterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Shopware\Core\Checkout\Payment\PaymentService;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoader;

/**
 * @RouteScope(scopes={"storefront"})
 */
class BuckarooController extends StorefrontController
{
    /**
     * @var EntityRepositoryInterface
     */
    private $countryRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $languageRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $localeRepository;

    /**
     * @var Buckaroo\Shopware6\Helpers\Config
     */
    protected $config;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $shippingMethodRepository;

    /**
     * @var DeliveryCalculator
     */
    protected $deliveryCalculator;

    /**
     * @var AmountCalculator
     */
    private $amountCalculator;

    /**
     * @var CartPersisterInterface
     */
    private $persister;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderCustomerRepository;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var OrderPersisterInterface
     */
    private $orderPersister;

    /**
     * @var PaymentService
     */
    private $paymentService;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $salutationRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerAddressRepository;

    /**
     * @var CheckoutFinishPageLoader
     */
    private $finishPageLoader;

    /**
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        EntityRepositoryInterface $countryRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $localeRepository,
        Config $config,
        CartService $cartService,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $shippingMethodRepository,
        DeliveryCalculator $deliveryCalculator,
        AmountCalculator $amountCalculator,
        CartPersisterInterface $persister,
        EntityRepositoryInterface $orderCustomerRepository,
        \Shopware\Core\Framework\Event\BusinessEventDispatcher $eventDispatcher,
        OrderPersisterInterface $orderPersister,
        PaymentService $paymentService,
        EntityRepositoryInterface $customerRepository,
        EntityRepositoryInterface $salutationRepository,
        EntityRepositoryInterface $customerAddressRepository,
        CheckoutFinishPageLoader $finishPageLoader,
        EntityRepositoryInterface $paymentMethodRepository,
        LoggerInterface $logger
    )
    {
        $this->countryRepository = $countryRepository;
        $this->languageRepository = $languageRepository;
        $this->localeRepository = $localeRepository;
        $this->config = $config;
        $this->cartService = $cartService;
        $this->orderRepository = $orderRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->deliveryCalculator = $deliveryCalculator;
        $this->amountCalculator = $amountCalculator;
        $this->persister = $persister;
        $this->orderCustomerRepository = $orderCustomerRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->orderPersister = $orderPersister;
        $this->paymentService = $paymentService;
        $this->customerRepository = $customerRepository;
        $this->salutationRepository = $salutationRepository;
        $this->customerAddressRepository = $customerAddressRepository;
        $this->finishPageLoader = $finishPageLoader;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->logger = $logger;
    }

    /**
     * @Route("/Buckaroo/getShopInformation", name="frontend.buckaroo.getShopInformation", options={"seo"="false"}, methods={"GET"})
     */
    public function getShopInformation(SalesChannelContext $context)
    {
        return $this->json([
            'merchant_id' => $this->config->guid()
        ]);
    }

    /**
     * @Route("/Buckaroo/applepayInit", name="frontend.buckaroo.applepayInit", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function applepayInit(SalesChannelContext $context)
    {
        $result = $this->applepayInitCommon($context);

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $this->applepayInitCommonCart($context, $cart, $result);

        return $this->json($result);
    }


    /**
     * @Route("/Buckaroo/applepayInitNonCheckout", name="frontend.buckaroo.applepayInitNonCheckout", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    public function applepayInitNonCheckout(Request $request, SalesChannelContext $context)
    {
        $this->logger->info(__METHOD__ . "|1|");

        $result = $this->applepayInitCommon($context);

        if ($request->request->get('product_id') && $request->request->get('qty')) {
            $mode = 'product';
        } else {
            $mode = 'cart';
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        if ($mode == 'product') {

            ////save and remove existing cart items
            $previousProducts = [];
            if ($previousLineItems = $cart->getLineItems()) {
                foreach ($previousLineItems as $lineItem) {
                    $previousProducts[] = [
                        'id' => $lineItem->getReferencedId(),
                        'qty' => $lineItem->getQuantity()
                    ];
                    $cart->remove($lineItem->getId());
                }
            }
            ////

            ////add new item
            $lineItem = (new ProductLineItemFactory())->create(
                $request->request->get('product_id'),
                ['quantity' => $request->request->get('qty')]
            );
            $this->cartService->add($cart, $lineItem, $context);
            /////
        }

        ////set custom shipping
        if ($request->request->get('selected_shipping_method')) {
            if ($cart = $this->setCustomShippingToCart($request, $context)) {
            } else {
                return $this->json(false);
            }
        }
        ////////////

        $amount = $this->amountCalculator->calculate(
            $cart->getLineItems()->getPrices(),
            $cart->getDeliveries()->getShippingCosts(),
            $context
        );
        $cart->setPrice($amount);
        $this->applepayInitCommonCart($context, $cart, $result);

        //////list possible shipping methods and prices
        $shippingMethods = [];

        $criteria = (new Criteria())->addFilter(new EqualsFilter('active', true));
        $shippingMethodsCollection = $this->shippingMethodRepository->search($criteria, $context->getContext())->getEntities();
        if ($shippingMethodsCollectionResult = $shippingMethodsCollection->filterByActiveRules($context)) {
            foreach ($shippingMethodsCollectionResult as $shippingMethod) {
                if ($delivery = $this->buildSingleDelivery($shippingMethod, $cart->getLineItems(), $context)) {

                    if ($deliveriesCollection = new DeliveryCollection([$delivery])) {
                        $cart->setDeliveries($deliveriesCollection);
                        $this->cartService->recalculate($cart, $context);
                        $this->deliveryCalculator->calculate($cart->getData(), $cart, $deliveriesCollection, $context);
                        foreach ($deliveriesCollection as $delivery) {
                            $shippingMethods[] = [
                                'label' => $delivery->getShippingMethod()->getName(),
                                'amount' => $delivery->getShippingCosts()->getTotalPrice(),
                                'identifier' => $delivery->getShippingMethod()->getId(),
                                'detail' => ''
                            ];
                        }

                    }
                }
            }
        }

        $result['shippingMethods'] = $shippingMethods;
        ////////////////////

        if ($mode == 'product') {
            $this->logger->info(__METHOD__ . "|10|");

            //revert cart back
            if ($lineItems = $cart->getLineItems()) {
                foreach ($lineItems as $lineItem) {
                    $this->logger->info(__METHOD__ . "|11|", [$lineItem->getId()]);
                    $cart->remove($lineItem->getId());
                }
            }

            if ($previousProducts) {
                $this->logger->info(__METHOD__ . "|12|");
                foreach ($previousProducts as $previousProduct) {
                    $this->logger->info(__METHOD__ . "|13|", [$previousProduct['id']]);
                    if (!empty($previousProduct['id'])) {
                        $lineItem = (new ProductLineItemFactory())->create(
                            $previousProduct['id'],
                            ['quantity' => $previousProduct['qty']]
                        );
                        $this->cartService->add($cart, $lineItem, $context);
                    }
                }
            }

        }

        return $this->json($result);
    }

    private function applepayInitCommon(SalesChannelContext $context)
    {
        $defaultContext = \Shopware\Core\Framework\Context::createDefaultContext();

        /** @var EntityCollection $countries */
        $countries = $this->countryRepository->search(
            new Criteria([$context->getSalesChannel()->getCountryId()]), $defaultContext
        );

        /** @var EntityCollection $languages */
        $languages = $this->languageRepository->search(
            new Criteria([$context->getSalesChannel()->getLanguageId()]), $defaultContext
        );

        /** @var EntityCollection $locales */
        $locales = $this->localeRepository->search(
            new Criteria([$languages->first() ? $languages->first()->getLocaleId() : '']), $defaultContext
        );

        return [
            'countryCode' => $countries->first() ? $countries->first()->getIso() : '',
            'currencyCode' => $context->getCurrency()->getIsoCode(),
            'cultureCode' => $locales->first() ? $locales->first()->getCode() : '',
            'storeName' => $context->getSalesChannel()->getName(),
        ];
    }

    private function applepayInitCommonCart(SalesChannelContext $context, $cart, &$result)
    {
        $lineItemsTotal = 0;
        $lineItemsDiscount = 0;
        if ($lineItemsPrices = $cart->getLineItems()->getPrices()) {
            foreach ($lineItemsPrices as $lineItemPrice) {
                $lineItemsTotal = $lineItemsTotal + $lineItemPrice->getTotalPrice();
                if ($lineItemPrice->getTotalPrice() < 0) {
                    $lineItemsDiscount = $lineItemsDiscount - $lineItemPrice->getTotalPrice();
                }
            }
        }

        $lineItems = [
            [
                'label' => $this->trans('bkr-applepay.Subtotal'),
                'amount' => round($lineItemsTotal, 2),
                'type' => 'final'
            ],
            [
                'label' => $this->trans('bkr-applepay.Deliverycosts'),
                'amount' => round($cart->getShippingCosts()->getTotalPrice(), 2),
                'type' => 'final'
            ]
        ];

        if ($lineItemsDiscount > 0) {
            $lineItems[] = [
                'label' => $this->trans('bkr-applepay.Discount'),
                'amount' => round($lineItemsDiscount, 2),
                'type' => 'final'
            ];
        }

        $result = array_merge($result, [
            'shippingMethods' => [],
            'shippingType' => 'shipping',
            'lineItems' => $lineItems,
            'totalLineItems' => [
                'label' => $context->getSalesChannel()->getName(),
                'amount' => round($cart->getPrice()->getTotalPrice(), 2),
                'type' => 'final'
            ]
        ]);
    }

    /**
     * @Route("/Buckaroo/applepaySaveOrder", name="frontend.buckaroo.applepaySaveOrder", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function applepaySaveOrder(Request $request, SalesChannelContext $salesChannelContext)
    {
        $this->logger->info(__METHOD__ . "|1|");

        $isLoggedIn = $salesChannelContext->getCustomer();

        $paymentData = $request->request->get('paymentData');

        $CustomerCardName = $paymentData['billingContact']['givenName'] . ' ' . $paymentData['billingContact']['familyName'];

        $this->logger->info(__METHOD__ . "|2|", [$isLoggedIn, $paymentData, $CustomerCardName]);

        $token = $paymentData['token'];

        if ($order = $this->createOrder($request, $salesChannelContext, $paymentData)) {

            $this->logger->info(__METHOD__ . "|3|");

            $user_id = $order->getOrderCustomer()->getCustomerNumber();
            $orderId = $order->getUniqueIdentifier();
            $order_number = $order->getOrderNumber();
            $amount = $order->getPrice()->getTotalPrice();

            $this->logger->info(__METHOD__ . "|4|", [$orderId]);

            if ($isLoggedIn) {
                $finishUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId]);
            } else {
                $finishUrl = $this->generateUrl('frontend.buckaroo.applepayFinishOrder', ['orderId' => $orderId]);
            }
            $this->logger->info(__METHOD__ . "|6|", [$finishUrl]);

            $errorUrl = $this->generateUrl('frontend.checkout.finish.page', [
                'orderId' => $orderId,
                'changedPayment' => false,
                'paymentFailed' => true,
            ]);

            $dataBag = new RequestDataBag();
            $dataBag->set('applePayInfo', json_encode($paymentData));
            $response = $this->paymentService->handlePaymentByOrder($orderId, $dataBag, $salesChannelContext, $finishUrl, $errorUrl);

            $this->logger->info(__METHOD__ . "|7|", [$response]);

            if ($response && $response->getTargetUrl()) {
                return $this->json([
                    'redirectURL' => (!$isLoggedIn && (strstr($response->getTargetUrl(), 'paymentFailed') === false)) ?
                        $finishUrl : $response->getTargetUrl()
                ]);
            }
        }

        return $this->json([
            'error' => 1
        ]);

        //$this->addFlash('danger', $this->trans('error.message-default'));
        //return new RedirectResponse('/checkout/confirm');

        return $this->json([
            'RequiredAction' => [
                'RedirectURL' => '/checkout/confirm'
            ],
        ]);

    }

    private function createOrder(Request $request, SalesChannelContext $context, $paymentData)
    {
        $this->logger->info(__METHOD__ . "|1|", [$request->request]);

        if (!$request->request->get('selected_shipping_method')) {
            return $this->json(false);
        }

        if ($request->request->get('items')) {
            $mode = 'product';
        } else {
            $mode = 'cart';
        }

        $context2 = null;
        if ($customer = $context->getCustomer()) {
            $this->logger->info(__METHOD__ . "|11|");
        } else {
            $this->logger->info(__METHOD__ . "|111|");
            if ($customer = $this->createAccount($context, $paymentData['billingContact'], $paymentData['shippingContact'])) {

                $event = new CustomerLoginEvent($context, $customer, $context->getToken());
                $this->eventDispatcher->dispatch($event);


                $context2 = new SalesChannelContext(
                    $context->getContext(),
                    $context->getToken(),
                    $context->getSalesChannel(),
                    $context->getCurrency(),
                    $context->getCurrentCustomerGroup(),
                    $context->getFallbackCustomerGroup(),
                    $context->getTaxRules(),
                    $context->getPaymentMethod(),
                    $context->getShippingMethod(),
                    $context->getShippingLocation(),
                    $customer,
                    []
                );

            }
        }

        if ($cart = $this->setCustomShippingToCart($request, $context, $customer)) {
            $this->logger->info(__METHOD__ . "|2|");
        } else {
            return $this->json(false);
        }

        if ($mode == 'product') {
            if ($previousLineItems = $cart->getLineItems()) {
                foreach ($previousLineItems as $lineItem) {
                    $cart->remove($lineItem->getId());
                }
            }
        }

        $this->logger->info(__METHOD__ . "|3|");

        if ($mode == 'product') {
            foreach ($request->request->get('items') as $item) {
                $lineItem = (new ProductLineItemFactory())->create(
                    $item['product_id'],
                    ['quantity' => $item['qty']]
                );
                $this->cartService->add($cart, $lineItem, $context);
            }
        }

        $amount = $this->amountCalculator->calculate(
            $cart->getLineItems()->getPrices(),
            $cart->getDeliveries()->getShippingCosts(),
            $context
        );
        $cart->setPrice($amount);

        $this->logger->info(__METHOD__ . "|4|");
        $this->persister->save($cart, $context);


        if ($context2) {
            $context = $context2;
        }

        if ($orderId = $this->order($cart, $context)) {
            if ($order = $this->getOrderById($orderId, $context)) {
                $this->logger->info(__METHOD__ . "|5|", [$orderId]);

                //restore previous items from order

                return $order;
            }
        }

        return $this->json(false);
    }

    private function getOrderById(string $orderId, SalesChannelContext $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('addresses.country');

        $order = $this->orderRepository->search($criteria, $context->getContext())->get($orderId);

        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }

        return $order;
    }

    private function buildSingleDelivery(
        ShippingMethodEntity $shippingMethod, LineItemCollection $collection, SalesChannelContext $context, $customer = null
    ): ?Delivery
    {
        $this->logger->info(__METHOD__ . "|1|");

        $positions = new DeliveryPositionCollection();

        foreach ($collection as $item) {
            if (!$item->getDeliveryInformation()) {
                continue;
            }

            // use shipping method delivery time as default
            $deliveryTime = DeliveryTime::createFromEntity($shippingMethod->getDeliveryTime());

            // each line item can override the delivery time
            if ($item->getDeliveryInformation()->getDeliveryTime()) {
                $deliveryTime = $item->getDeliveryInformation()->getDeliveryTime();
            }

            // create the estimated delivery date by detected delivery time
            $deliveryDate = DeliveryDate::createFromDeliveryTime($deliveryTime);

            // create a restock date based on the detected delivery time
            $restockDate = DeliveryDate::createFromDeliveryTime($deliveryTime);

            $restockTime = $item->getDeliveryInformation()->getRestockTime();

            // if the line item has a restock time, add this days to the restock date
            if ($restockTime) {
                $restockDate = $restockDate->add(new \DateInterval('P' . $restockTime . 'D'));
            }

            // if the item is completely instock, use the delivery date
            if ($item->getDeliveryInformation()->getStock() >= $item->getQuantity()) {
                $position = new DeliveryPosition($item->getId(), clone $item, $item->getQuantity(), $item->getPrice(), $deliveryDate);
            } else {
                // otherwise use the restock date as delivery date
                $position = new DeliveryPosition($item->getId(), clone $item, $item->getQuantity(), $item->getPrice(), $restockDate);
            }

            $positions->add($position);
        }

        if ($positions->count() <= 0) {
            return null;
        }

        if ($customer) {
            $this->logger->info(__METHOD__ . "|5|", [$customer->getDefaultShippingAddressId()]);
            $criteria = (new Criteria([$customer->getDefaultShippingAddressId()]))->setLimit(1);
            $criteria->addAssociation('country');
            $shippingAddress = $this->customerAddressRepository->search($criteria, $context->getContext())->first();
            $this->logger->info(__METHOD__ . "|6|", [$shippingAddress]);
            $shippingLocation = \Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation::createFromAddress(
                $shippingAddress
            );
        } else {
            $shippingLocation = $context->getShippingLocation();
        }

        return new Delivery(
            $positions,
            $this->getDeliveryDateByPositions($positions),
            $shippingMethod,
            $shippingLocation,
            new CalculatedPrice(0, 0, new CalculatedTaxCollection(), new TaxRuleCollection())
        );
    }

    private function getDeliveryDateByPositions(DeliveryPositionCollection $positions): DeliveryDate
    {
        // this function is only called if the provided collection contains a deliverable line item
        $max = $positions->first()->getDeliveryDate();

        foreach ($positions as $position) {
            $date = $position->getDeliveryDate();

            // detect the latest delivery date
            $earliest = $max->getEarliest() > $date->getEarliest() ? $max->getEarliest() : $date->getEarliest();

            $latest = $max->getLatest() > $date->getLatest() ? $max->getLatest() : $date->getLatest();

            // if earliest and latest is same date, add one day buffer
            if ($earliest->format('Y-m-d') === $latest->format('Y-m-d')) {
                $latest = $latest->add(new \DateInterval('P1D'));
            }

            $max = new DeliveryDate($earliest, $latest);
        }

        return $max;
    }

    private function setCustomShippingToCart(Request $request, SalesChannelContext $context, $customer = null)
    {
        $this->logger->info(__METHOD__ . "|1|", [$customer]);

        if ($request->request->get('selected_shipping_method')) {
            $this->logger->info(__METHOD__ . "|2|", [$request->request->get('selected_shipping_method')]);
            $criteria = new Criteria([$request->request->get('selected_shipping_method')]);
            $shippingMethod = $this->shippingMethodRepository->search(
                $criteria,
                $context->getContext())->get($request->request->get('selected_shipping_method')
            );

            if (!$shippingMethod || !$shippingMethod->getId()) {
                return false;
            }

            $context->getSalesChannel()->setShippingMethodId($shippingMethod->getId());
            $context->getSalesChannel()->setShippingMethod($shippingMethod);

            $cart = $this->cartService->getCart($context->getToken(), $context);

            if ($delivery = $this->buildSingleDelivery($shippingMethod, $cart->getLineItems(), $context, $customer)) {
                if ($deliveriesCollection = new DeliveryCollection([$delivery])) {
                    $cart->setDeliveries($deliveriesCollection);
                    $this->cartService->recalculate($cart, $context);
                    $this->deliveryCalculator->calculate($cart->getData(), $cart, $deliveriesCollection, $context);
                    $this->logger->info(__METHOD__ . "|3|", [$deliveriesCollection->first()->getLocation()]);
                }
            }

            $amount = $this->amountCalculator->calculate(
                $cart->getLineItems()->getPrices(),
                $cart->getDeliveries()->getShippingCosts(),
                $context
            );
            $cart->setPrice($amount);

            $this->logger->info(__METHOD__ . "|4|");

            return $cart;
        }
    }

    /**
     * @throws InvalidOrderException
     * @throws InconsistentCriteriaIdsException
     */
    private function order(Cart $cart, SalesChannelContext $context): string
    {
        $this->logger->info(__METHOD__ . "|1|");
        //$calculatedCart = $this->calculate($cart, $context);
        $orderId = $this->orderPersister->persist($cart, $context);

        $this->logger->info(__METHOD__ . "|2|");

        $criteria = new Criteria([$orderId]);
        $criteria
            ->addAssociation('lineItems.payload')
            ->addAssociation('deliveries.shippingCosts')
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('deliveries.shippingOrderAddress.country')
            ->addAssociation('cartPrice.calculatedTaxes')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('currency')
            ->addAssociation('addresses.country');

        /** @var OrderEntity|null $orderEntity */
        $orderEntity = $this->orderRepository->search($criteria, $context->getContext())->first();

        if (!$orderEntity) {
            throw new InvalidOrderException($orderId);
        }

        $orderEntity->setOrderCustomer(
            $this->fetchCustomer($orderEntity->getId(), $context->getContext())
        );

        $this->logger->info(__METHOD__ . "|3|");

        $orderPlacedEvent = new CheckoutOrderPlacedEvent(
            $context->getContext(),
            $orderEntity,
            $context->getSalesChannel()->getId()
        );

        $this->eventDispatcher->dispatch($orderPlacedEvent);

        $this->logger->info(__METHOD__ . "|4|");

        $this->persister->delete($context->getToken(), $context);
        unset($this->cart[$cart->getToken()]);

        return $orderId;
    }

    private function fetchCustomer(string $orderId, Context $context): OrderCustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociation('customer');
        $criteria->addAssociation('salutation');

        return $this->orderCustomerRepository
            ->search($criteria, $context)
            ->first();
    }

    protected function generateUrl(string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->container->get('router')->generate($route, $parameters, $referenceType);
    }

    private function createAccount(SalesChannelContext $context, $billing_address, $shipping_address)
    {
        $this->logger->info(__METHOD__ . "|1|", [$billing_address, $shipping_address]);

        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();
        $addressId2 = Uuid::randomHex();

        $this->logger->info(__METHOD__ . "|2|", [$customerId, $addressId, $addressId2]);

        $this->customerRepository->create([
            [
                'id' => $customerId,
                'salesChannelId' => $context->getSalesChannel()->getUniqueIdentifier(),
                'defaultShippingAddress' => [
                    'id' => $addressId,
                    'firstName' => $shipping_address['givenName'],
                    'lastName' => $shipping_address['familyName'],
                    'street' => join(' ', $shipping_address['addressLines']),
                    'city' => $shipping_address['locality'],
                    'zipcode' => $shipping_address['postalCode'],
                    'salutationId' => $this->getValidSalutationId($context),
                    'country' => ['name' => $shipping_address['country']],
                ],
                'defaultShippingAddress' => [
                    'id' => $addressId2,
                    'firstName' => $billing_address['givenName'],
                    'lastName' => $billing_address['familyName'],
                    'street' => join(' ', $billing_address['addressLines']),
                    'city' => $billing_address['locality'],
                    'zipcode' => $billing_address['postalCode'],
                    'salutationId' => $this->getValidSalutationId($context),
                    'country' => ['name' => $shipping_address['country']],
                ],
                'defaultShippingAddressId' => $addressId,
                'defaultBillingAddressId' => $addressId2,
                'defaultPaymentMethodId' => $this->getValidPaymentMethodId($context),
                /*
                'defaultPaymentMethod' => [
                    'name' => 'Apple Pay',
                    'description' => 'Pay with Apple Pay',
                    'handlerIdentifier' => \Buckaroo\Shopware6\Handlers\ApplePayPaymentHandler::class,
                ],
                */
                'groupId' => Defaults::FALLBACK_CUSTOMER_GROUP,
                'email' => $shipping_address['emailAddress'],
                'password' => $customerId,
                'firstName' => $billing_address['givenName'],
                'lastName' => $billing_address['familyName'],
                'salutationId' => $this->getValidSalutationId($context),
                'customerNumber' => '12345',
            ],
        ], $context->getContext());

        $criteria = (new Criteria([$customerId]))->setLimit(1);
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultShippingAddress');
        return $this->customerRepository->search($criteria, $context->getContext())->first();


    }

    protected function getValidPaymentMethodId(SalesChannelContext $context): string
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('handlerIdentifier', 'Buckaroo\Shopware6\Handlers\ApplePayPaymentHandler'));

        return $this->paymentMethodRepository->search($criteria, $context->getContext())->first()->getId();
    }

    protected function getValidSalutationId(SalesChannelContext $context): string
    {
        $criteria = (new Criteria())->setLimit(1);
        return $this->salutationRepository->searchIds($criteria, $context->getContext())->getIds()[0];
    }

    /**
     * @Route("/Buckaroo/applepayFinishOrder", name="frontend.buckaroo.applepayFinishOrder", options={"seo"="false"}, methods={"GET"})
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function applepayFinishOrder(Request $request, SalesChannelContext $salesChannelContext)
    {
        $this->logger->info(__METHOD__ . "|1|");

        $orderId = $request->get('orderId');
        if (!$orderId) {
            throw new MissingRequestParameterException('orderId', '/orderId');
        }

        try {
            $this->logger->info(__METHOD__ . "|2|");
            $criteria = (new Criteria([$orderId]))
                ->addAssociation('orderCustomer')
                ->addAssociation('orderCustomer.customer')
                ->addAssociation('orderCustomer.customer.defaultBillingAddress')
                ->addAssociation('orderCustomer.customer.defaultShippingAddress')
            ;
            $order = $this->orderRepository->search($criteria, $salesChannelContext->getContext())->first();
            $this->logger->info(__METHOD__ . "|3|");
        } catch (InvalidUuidException $e) {
            throw new OrderNotFoundException($orderId);
        }

        $this->logger->info(__METHOD__ . "|4|", [$order->getOrderCustomer()->getCustomer()]);

        $salesChannelContext = new SalesChannelContext(
            $salesChannelContext->getContext(),
            $salesChannelContext->getToken(),
            $salesChannelContext->getSalesChannel(),
            $salesChannelContext->getCurrency(),
            $salesChannelContext->getCurrentCustomerGroup(),
            $salesChannelContext->getFallbackCustomerGroup(),
            $salesChannelContext->getTaxRules(),
            $salesChannelContext->getPaymentMethod(),
            $salesChannelContext->getShippingMethod(),
            $salesChannelContext->getShippingLocation(),
            $order->getOrderCustomer()->getCustomer(),
            []
        );

        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $salesChannelContext);

        $page = $this->finishPageLoader->load($request, $salesChannelContext);

        $this->logger->info(__METHOD__ . "|4|", [$page]);

        return $this->renderStorefront('@Storefront/storefront/page/checkout/finish/index.html.twig', ['page' => $page]);
    }
}
