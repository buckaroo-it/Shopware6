<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Buckaroo\Shopware6\Service\Config\PageFactory;
use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Account\PaymentMethod\AccountPaymentMethodPageLoadedEvent;

class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{
    protected SettingsService $settingsService;
    protected TranslatorInterface $translator;
    protected PageFactory $pageFactory;

    public function __construct(
        SettingsService $settingsService,
        TranslatorInterface $translator,
        PageFactory $pageFactory
    ) {
        $this->settingsService = $settingsService;
        $this->translator = $translator;
        $this->pageFactory = $pageFactory;
    }

    /**
     * @return array<mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AccountPaymentMethodPageLoadedEvent::class => 'hideNotEnabledPaymentMethods',
            AccountEditOrderPageLoadedEvent::class     => 'addBuckarooExtension',
            CheckoutConfirmPageLoadedEvent::class      => 'addBuckarooExtension',
            ProductPageLoadedEvent::class              => 'addBuckarooToProductPage',
            CheckoutCartPageLoadedEvent::class         => 'addBuckarooToCart',
            CheckoutFinishPageLoadedEvent::class       => 'addInProgress'
        ];
    }

    /**
     * @param AccountEditOrderPageLoadedEvent|AccountPaymentMethodPageLoadedEvent|CheckoutConfirmPageLoadedEvent $event
     */
    public function hideNotEnabledPaymentMethods($event): void
    {
        $paymentMethods = $event->getPage()->getPaymentMethods();
        $salesChannelId =  $event->getSalesChannelContext()->getSalesChannelId();

        $event->getPage()->setPaymentMethods(
            $paymentMethods->filter(function ($paymentMethod) use ($salesChannelId, $event) {
                $buckarooKey = $this->getBuckarooKey($paymentMethod->getTranslated());

                if ($buckarooKey === 'payperemail') {
                    return $this->isPaymentEnabled($buckarooKey, $salesChannelId) &&
                        !$this->isPayPermMailDisabledInFrontend($salesChannelId);
                }

                if ($buckarooKey === 'afterpay') {
                    return $this->isPaymentEnabled($buckarooKey, $salesChannelId) &&
                        $this->canShowAfterpay($event);
                }

                return $buckarooKey === null || $this->isPaymentEnabled($buckarooKey, $salesChannelId);
            })
        );
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent|AccountEditOrderPageLoadedEvent $event
     * @throws \Exception
     */
    public function addBuckarooExtension($event): void
    {
        $this->hideNotEnabledPaymentMethods($event);
        $this->addExtension($event, 'checkout');
    }

    public function addBuckarooToCart(CheckoutCartPageLoadedEvent $event): void
    {
        $this->addExtension($event, 'cart');
    }

    public function addBuckarooToProductPage(ProductPageLoadedEvent $event): void
    {
        $this->addExtension($event, 'product');
    }

    /**
     * Display flash message when order is in progress
     *
     * @param CheckoutFinishPageLoadedEvent $event
     *
     * @return void
     */
    public function addInProgress(CheckoutFinishPageLoadedEvent $event): void
    {

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity|null */
        $transaction = $event->getPage()->getOrder()->getTransactions()?->last();

        if ($transaction === null) {
            return;
        }

        $paymentMethod = $transaction->getPaymentMethod();
        $stateMachine = $transaction->getStateMachineState();
        if ($paymentMethod === null || $stateMachine === null) {
            return;
        }

        /** @var \Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface */
        $session = $event->getRequest()->getSession();

        if (
            strpos($paymentMethod->getHandlerIdentifier(), 'Buckaroo\Shopware6\Handlers') !== false &&
            $stateMachine->getTechnicalName() === 'in_progress' &&
            method_exists($session, 'getFlashBag')
        ) {
            $session->getFlashBag()->add('success', $this->translator->trans('buckaroo.messages.return791'));
        }
    }


    private function addExtension($event, string $page): void
    {
        $event->getPage()->addExtension(
            BuckarooStruct::EXTENSION_NAME,
            $this->pageFactory->get($event->getSalesChannelContext(), $page)
        );
    }

    /**
     * Check if payment is enabled in our settings
     *
     * @param string $buckarooKey
     * @param string|null $salesChannelId
     *
     * @return boolean
     */
    private function isPaymentEnabled(string $buckarooKey, string $salesChannelId = null)
    {
        return $this->settingsService->getEnabled($buckarooKey, $salesChannelId);
    }

    /**
     * Check if paypermail can be shown in the checkout
     *
     * @param string|null $salesChannelId
     *
     * @return boolean
     */
    private function isPayPermMailDisabledInFrontend(string $salesChannelId = null): bool
    {
        return $this->settingsService->getSetting('payperemailEnabledfrontend', $salesChannelId) === false;
    }

    /**
     * Check if we can display afterpay when b2b is enabled
     *
     * @param AccountEditOrderPageLoadedEvent|AccountPaymentMethodPageLoadedEvent|CheckoutConfirmPageLoadedEvent $event
     *
     * @return boolean
     */
    protected function canShowAfterpay($event): bool
    {
        $shippingCompany = null;
        $billingCompany = null;

        $isStrictB2B = $this->settingsService->getSetting(
            'afterpayCustomerType',
            $event->getSalesChannelContext()->getSalesChannelId()
        ) === AfterPayPaymentHandler::CUSTOMER_TYPE_B2B;

        if (!$isStrictB2B) {
            return true;
        }

        if ($event instanceof AccountEditOrderPageLoadedEvent) {
            $order = $event->getPage()->getOrder();

            $billingAddress = $order->getBillingAddress();
            if ($billingAddress !== null) {
                $billingCompany = $billingAddress->getCompany();
            }

            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity */
            $shippingAddress = $order->getDeliveries()?->getShippingAddress()->first();
            if ($shippingAddress !== null) {
                $shippingCompany = $shippingAddress->getCompany();
            }
        }

        if ($event instanceof CheckoutConfirmPageLoadedEvent) {
            $customer = $event->getSalesChannelContext()->getCustomer();

            if ($customer === null) {
                return false;
            }

            $billingAddress = $customer->getDefaultBillingAddress();

            if ($billingAddress !== null) {
                $billingCompany = $billingAddress->getCompany();
            }

            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity */
            $shippingAddress = $customer->getDefaultShippingAddress();
            if ($shippingAddress !== null) {
                $shippingCompany = $shippingAddress->getCompany();
            }
        }
        return !($this->isCompanyEmpty($billingCompany) &&
            $this->isCompanyEmpty($shippingCompany));
    }

    /**
     * Check if company is empty
     *
     * @param string $company
     *
     * @return boolean
     */
    private function isCompanyEmpty(string $company = null)
    {
        return null === $company || strlen(trim($company)) === 0;
    }

    /**
     * Get buckaroo config key from payment transaction
     *
     * @param array $translation
     *
     * @return string|null
     */
    private function getBuckarooKey(array $translation): ?string
    {
        if (!isset($translation['customFields']) || !is_array($translation['customFields'])) {
            return null;
        }

        if (
            !isset($translation['customFields']['buckaroo_key']) ||
            !is_string($translation['customFields']['buckaroo_key'])
        ) {
            return null;
        }
        return $translation['customFields']['buckaroo_key'];
    }
}
