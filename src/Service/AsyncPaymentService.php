<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Buckaroo\Shopware6\Service\PaymentStateService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;

class AsyncPaymentService
{
    public ClientService $clientService;
    
    public SettingsService $settingsService;

    public UrlService $urlService;

    public StateTransitionService $stateTransitionService;

    public CheckoutHelper $checkoutHelper;

    /**
     * @var LoggerInterface
     */
    public $logger;
    
    public FormatRequestParamService $formatRequestParamService;

    public PaymentStateService $paymentStateService;

    protected EventDispatcherInterface $eventDispatcher;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        SettingsService $settingsService,
        UrlService $urlService,
        StateTransitionService $stateTransitionService,
        ClientService $clientService,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger,
        FormatRequestParamService $formatRequestParamService,
        PaymentStateService $paymentStateService,
        EventDispatcherInterface $eventDispatcher
    ) {

        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->stateTransitionService = $stateTransitionService;
        $this->clientService = $clientService;
        $this->checkoutHelper = $checkoutHelper;
        $this->logger = $logger;
        $this->formatRequestParamService = $formatRequestParamService;
        $this->paymentStateService = $paymentStateService;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param OrderEntity $order
     *
     * @return OrderCustomerEntity
     */
    public function getCustomer(OrderEntity $order): OrderCustomerEntity
    {
        $customer = $order->getOrderCustomer();
        if ($customer === null) {
            throw new \InvalidArgumentException('Customer cannot be null');
        }
        return $customer;
    }

    /**
     * @param OrderEntity $order
     *
     * @return OrderAddressEntity
     */
    public function getBillingAddress(OrderEntity $order): OrderAddressEntity
    {
        $address = $order->getBillingAddress();
        if ($address === null) {
            throw new \InvalidArgumentException('Billing address cannot be null');
        }
        return $address;
    }

    /**
     * @param OrderEntity $order
     *
     * @return OrderAddressEntity
     */
    public function getShippingAddress(OrderEntity $order): OrderAddressEntity
    {
        $deliveries = $order->getDeliveries();

        if ($deliveries === null) {
            throw new \InvalidArgumentException('Deliveries cannot be null');
        }

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
        $address = $deliveries->getShippingAddress()->first();
        if ($address === null) {
            $address = $this->getBillingAddress($order);
        }

        return $address;
    }

    /**
     * @param OrderAddressEntity $orderAddress
     *
     * @return CountryEntity
     */
    public function getCountry(OrderAddressEntity $orderAddress): CountryEntity
    {
        $country = $orderAddress->getCountry();

        if ($country === null) {
            throw new \InvalidArgumentException('Shipping country cannot be null');
        }
        return $country;
    }

    /**
     * @param OrderEntity $order
     *
     * @return CurrencyEntity
     */
    public function getCurrency(OrderEntity $order): CurrencyEntity
    {
        $address = $order->getCurrency();
        if ($address === null) {
            throw new \InvalidArgumentException('Billing address cannot be null');
        }
        return $address;
    }
    
    public function dispatchEvent(ShopwareSalesChannelEvent $event): object
    {
        return $this->eventDispatcher->dispatch($event);
    }
}
