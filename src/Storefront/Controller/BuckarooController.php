<?php
declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Helpers\Config;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Checkout\Payment\Exception\UnknownPaymentMethodException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

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

    public function __construct(
        EntityRepositoryInterface $countryRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $localeRepository,
        Config $config,
        CartService $cartService,
        EntityRepositoryInterface $orderRepository
    )
    {
        $this->countryRepository = $countryRepository;
        $this->languageRepository = $languageRepository;
        $this->localeRepository = $localeRepository;
        $this->config = $config;
        $this->cartService = $cartService;
        $this->orderRepository = $orderRepository;
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
        $cart = $this->cartService->getCart($context->getToken(), $context);

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
                'amount' => $lineItemsTotal,
                'type' => 'final'
            ],
            [
                'label' => $this->trans('bkr-applepay.Deliverycosts'),
                'amount' => $cart->getShippingCosts()->getTotalPrice(),
                'type' => 'final'
            ]
        ];

        if ($lineItemsDiscount > 0) {
            $lineItems[] = [
                'label' => $this->trans('bkr-applepay.Discount'),
                'amount' => $lineItemsDiscount,
                'type' => 'final'
            ];
        }

        return $this->json([
            'countryCode' => $countries->first() ? $countries->first()->getIso() : '',
            'currencyCode' => $context->getCurrency()->getIsoCode(),
            'cultureCode' => $locales->first() ? $locales->first()->getCode() : '',
            'shippingMethods' => [],
            'shippingType' => 'shipping',
            'storeName' => $context->getSalesChannel()->getName(),
            'lineItems' => $lineItems,
            'totalLineItems' => [
                'label' => $context->getSalesChannel()->getName(),
                'amount' => $cart->getPrice()->getTotalPrice(),
                'type' => 'final'
            ],
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
    /*
    public function applepaySaveOrder(Request $request, SalesChannelContext $salesChannelContext)
    {
        $paymentData = $request->request->get('paymentData');

        $CustomerCardName = $paymentData['billingContact']['givenName'] . ' ' . $paymentData['billingContact']['familyName'];

        $token = $paymentData['token'];
        if ($order = $this->createOrder($salesChannelContext)) {

            $user_id = $order->getOrderCustomer()->getCustomerNumber();
            $order_id = $order->getUniqueIdentifier();
            $order_number = $order->getOrderNumber();
            $amount = $order->getPrice()->getTotalPrice();

            var_dump("=====applepaySaveOrder", $user_id, $order_id , $order_number, $amount);

            $this->completeOrder($amount, $token, $user_id, $order, $order_id, $order_number, $CustomerCardName, $salesChannelContext);
        }

        return $this->json([
            'xxx' => 1
        ]);

        //$this->addFlash('danger', $this->trans('error.message-default'));
        //return new RedirectResponse('/checkout/confirm');

        return $this->json([
            'RequiredAction' => [
                'RedirectURL' => '/checkout/confirm'
            ],
        ]);
    }

    private function createOrder(SalesChannelContext $salesChannelContext)
    {
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        if ($orderId = $this->cartService->order($cart, $salesChannelContext)) {
            if ($order = $this->getOrderById($orderId, $salesChannelContext)) {
                return $order;
            }
        }
    }

    private function getOrderById(string $orderId, SalesChannelContext $context): OrderEntity
    {
        var_dump("==============".__METHOD__."===1");
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('addresses.country');

        $order = $this->orderRepository->search($criteria, $context->getContext())->get($orderId);

        if ($order === null) {
            var_dump("==============".__METHOD__."===2");
            throw new OrderNotFoundException($orderId);
        }

        var_dump("==============".__METHOD__."===3");


        return $order;
    }
    */
}
