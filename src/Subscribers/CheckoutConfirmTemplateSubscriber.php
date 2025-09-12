<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Buckaroo\Shopware6\PaymentMethods\In3;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\PayByBankService;
use Shopware\Core\System\Currency\CurrencyEntity;
use Buckaroo\Shopware6\Service\IdealIssuerService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;
use Buckaroo\Shopware6\Handlers\CreditcardPaymentHandler;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Storefront\Page\Account\PaymentMethod\AccountPaymentMethodPageLoadedEvent;

class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{
    /** @var CheckoutHelper $checkoutHelper */
    public CheckoutHelper $checkoutHelper;

    /**
     * @var SalesChannelRepository
     */
    private SalesChannelRepository $paymentMethodRepository;

    private IdealIssuerService $idealIssuerService;


    /**
     * @var array<mixed>
     */
    protected array $availableCreditcards = [
        'mastercard'     => 'MasterCard',
        'visa'           => 'Visa',
        'amex'           => 'American Express',
        'vpay'           => 'VPay',
        'maestro'        => 'Maestro',
        'visaelectron'   => 'Visa Electron',
        'cartebleuevisa' => 'Carte Bleue',
        'cartebancaire'  => 'Carte Bancaire',
        'dankort'        => 'Dankort',
        'nexi'           => 'Nexi',
        'postepay'       => 'PostePay',
    ];

    protected SettingsService $settingsService;
    protected UrlService $urlService;
    protected TranslatorInterface $translator;
    protected PayByBankService $payByBankService;
    protected In3LogoService $in3LogoService;

    public function __construct(
        SalesChannelRepository $paymentMethodRepository,
        SettingsService $settingsService,
        UrlService $urlService,
        TranslatorInterface $translator,
        PayByBankService $payByBankService,
        In3LogoService $in3LogoService,
        IdealIssuerService $idealIssuerService
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->translator = $translator;
        $this->payByBankService = $payByBankService;
        $this->in3LogoService = $in3LogoService;
        $this->idealIssuerService = $idealIssuerService;
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
    /**
     * @param AccountEditOrderPageLoadedEvent|AccountPaymentMethodPageLoadedEvent|CheckoutConfirmPageLoadedEvent $event
     */
    public function hideNotEnabledPaymentMethods($event): void
    {
        $paymentMethods = $event->getPage()->getPaymentMethods();
        foreach ($paymentMethods as $paymentMethod) {
            $buckarooKey = $this->getBuckarooKey($paymentMethod->getTranslated());
            if ($buckarooKey === null) {
                continue;
            }

            if (!$this->settingsService->getEnabled(
                $buckarooKey,
                $event->getSalesChannelContext()->getSalesChannelId()
            )) {
                $paymentMethods = $this->removePaymentMethod($paymentMethods, $paymentMethod->getId());
            }

            if (
                $buckarooKey === 'payperemail' &&
                $this->isPayPermMailDisabledInFrontend(
                    $event->getSalesChannelContext()->getSalesChannelId()
                )
            ) {
                $paymentMethods = $this->removePaymentMethod($paymentMethods, $paymentMethod->getId());
            }

            if ($buckarooKey === 'afterpay' && !$this->canShowAfterpay($event)) {
                $paymentMethods = $this->removePaymentMethod($paymentMethods, $paymentMethod->getId());
            }
        }
        $event->getPage()->setPaymentMethods($paymentMethods);
    }

    public function isPayPermMailDisabledInFrontend(string $salesChannelId = null): bool
    {
        return $this->settingsService->getSetting('payperemailEnabledfrontend', $salesChannelId) === false;
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent|AccountEditOrderPageLoadedEvent $event
     * @throws \Exception
     */
    public function addBuckarooExtension($event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        $this->hideNotEnabledPaymentMethods($event);

        $request  = $event->getRequest();
        $customer = $event->getSalesChannelContext()->getCustomer();

        if ($customer === null) {
            throw new \InvalidArgumentException('Cannot find customer');
        }

        $paymentMethodTranslation = $event->getSalesChannelContext()->getPaymentMethod()->getTranslated();
        $buckarooKey = $this->getBuckarooKey($paymentMethodTranslation);
        if ($buckarooKey === null) {
            $buckarooKey = '';
        }

        $currency = $this->getCurrency($event);


        $struct             = new BuckarooStruct();
        $idealRenderMode    = $this->getIdealRenderMode($salesChannelId);
        $lastUsedCreditcard = $customer->getCustomFieldsValue(CreditcardPaymentHandler::ISSUER_LABEL);

        if (!is_string($lastUsedCreditcard)) {
            $lastUsedCreditcard = 'visa';
        }

        $creditcard = [];
        $allowedcreditcard = $this->settingsService->getSetting('allowedcreditcard', $salesChannelId);
        if (!empty($allowedcreditcard) && is_array($allowedcreditcard)) {
            foreach ($allowedcreditcard as $value) {
                $label  = null;
                if (
                    isset($this->availableCreditcards[$value]) &&
                    is_string($this->availableCreditcards[$value])
                ) {
                    $label = (string)$this->availableCreditcards[$value];
                }

                $creditcard[] = [
                    'name' => $this->getBuckarooFeeLabel(
                        'allowedcreditcard',
                        $currency,
                        $salesChannelId,
                        $label,
                    ),
                    'code' => $value,
                ];
            }
        }

        $creditcards = [];
        $allowedcreditcards = $this->settingsService->getSetting('allowedcreditcards', $salesChannelId);
        if (!empty($allowedcreditcards) && is_array($allowedcreditcards)) {
            foreach ($allowedcreditcards as $value) {
                $label  = null;

                if (
                    isset($this->availableCreditcards[$value]) &&
                    is_string($this->availableCreditcards[$value])
                ) {
                    $label = (string)$this->availableCreditcards[$value];
                }

                $creditcards[] = [
                    'name' => $this->getBuckarooFeeLabel(
                        'allowedcreditcards',
                        $currency,
                        $salesChannelId,
                        $label,
                    ),
                    'code' => $value,
                ];
            }
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('active', true))
            ->addAssociation('media');
        $paymentLabels = [];
        /** @var PaymentMethodCollection $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository
            ->search(
                $criteria,
                $event->getSalesChannelContext()
            )
            ->getEntities();

        foreach ($paymentMethods as $paymentMethod) {
            $buckarooPaymentKey = $this->getBuckarooKey($paymentMethod->getTranslated());
            if ($buckarooPaymentKey !== null) {
                $paymentLabels[$buckarooPaymentKey] = $this->getBuckarooFeeLabel(
                    $buckarooPaymentKey,
                    $currency,
                    $salesChannelId
                );
            }
        }

        $backUrl = $this->urlService->getRestoreUrl();

        $struct->assign([
            'currency'                 => $currency->getIsoCode(),
            'issuers'                  => $this->idealIssuerService->get($salesChannelId),
            'idealRenderMode'          => $idealRenderMode,
            'idealProcessingRenderMode'=> $this->getIdealProcessingRenderMode($salesChannelId),
            'showIssuers'         => $this->canShowIssuers($salesChannelId, $buckarooKey),
            'payByBankMode'            => $this->settingsService->getSetting('paybybankRenderMode', $salesChannelId),
            'payByBankIssuers'         => $this->payByBankService->getIssuers($customer),
            'payByBankLogos'           => $this->payByBankService->getIssuerLogos($customer),
            'payByBankActiveIssuer'    => $this->payByBankService->getActiveIssuer($customer),
            'in3Logo'                  => $this->in3LogoService->getActiveLogo(
                $this->settingsService->getSetting('capayableVersion', $salesChannelId),
                $event->getSalesChannelContext()->getContext()
            ),
            'idealFastCheckoutLogo'    => $this->getIdealFastCheckoutLogo($salesChannelId),
            'payment_method_name_card' => $this->getPaymentMethodName($creditcard, $lastUsedCreditcard, ''),
            'creditcard'               => $creditcard,
            'creditcards'              => $creditcards,
            'last_used_creditcard'     => $lastUsedCreditcard,
            'payment_labels'           => $paymentLabels,
            'payment_media'            => $lastUsedCreditcard . '.png',
            'buckarooFee'              => $this->settingsService->getBuckarooFee($buckarooKey, $salesChannelId),
            'BillinkBusiness'          => $this->getBillinkPaymentType($customer),
            'backLink'                 => $backUrl,
            'afterpay_customer_type'   => $this->settingsService->getSetting('afterpayCustomerType', $salesChannelId),
            'showPaypalExpress'        => $this->showPaypalExpress($salesChannelId, 'checkout'),
            'showIdealFastCheckout'    => $this->showIdealFastCheckout($salesChannelId, 'checkout'),
            'paypalMerchantId'         => $this->getPaypalExpressMerchantId($salesChannelId),
            'applepayHostedPaymentPage' =>
                intval($this->settingsService->getSetting('applepayHostedPaymentPage', $salesChannelId)) === 1,
            'applePayMerchantId'       => $this->getAppleMerchantId($salesChannelId),
            'websiteKey'               => $this->settingsService->getSetting('websiteKey', $salesChannelId),
            'canShowPhone'          => $this->canShowPhone($customer),
            'methodsWithFinancialWarning' => $this->getMethodsWithFinancialWarning($salesChannelId),
            'validHouseNumbers'     => $this->areValidHouseNumbers($event),
            'afterpayOld' => $this->settingsService->getSetting('afterpayEnabledold', $salesChannelId) === true,
            'redirectBancontact' =>
                $this->settingsService->getSetting('redirectBancontact', $salesChannelId) === 'enabledRedirect']);

        $event->getPage()->addExtension(
            BuckarooStruct::EXTENSION_NAME,
            $struct
        );
    }

    private function getIdealProcessingRenderMode(string $salesChannelId): int
    {
        $mode = $this->settingsService->getSetting('idealprocessingRenderMode', $salesChannelId);
        if (is_scalar($mode)) {
            return (int)$mode;
        }
        return 0;
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent|AccountEditOrderPageLoadedEvent $event
     * @return array<mixed>
     */
    private function areValidHouseNumbers($event): array
    {

        if (method_exists($event->getPage(), "getOrder")) {
            /** @var AccountEditOrderPageLoadedEvent $event */
            $billingAddress = $event->getPage()->getOrder()->getBillingAddress();
            $shippingAddress = $event->getPage()->getOrder()->getDeliveries()?->getShippingAddress()->first();
        } else {
            $billingAddress = $event->getSalesChannelContext()->getCustomer()?->getDefaultBillingAddress();
            $shippingAddress = $event->getSalesChannelContext()->getCustomer()?->getDefaultShippingAddress();
        }

        return [
            "billing" => $this->isHouseNumberValid($billingAddress),
            "shipping" => $this->isHouseNumberValid($shippingAddress)
        ];
    }

    /**
     * Check if we have a valid house number in a address
     *
     * @param CustomerAddressEntity|OrderAddressEntity|null $address
     *
     * @return boolean
     */
    private function isHouseNumberValid($address): bool
    {
        if ($address === null) {
            return false;
        }

        $parts = FormatRequestParamService::getAddressParts($address->getStreet());
        return is_string($parts['house_number']) && !empty(trim($parts['house_number']));
    }

    private function canShowIssuers(string $salesChannelId, string $key): bool
    {
        return $this->settingsService->getSetting($key . "Showissuers", $salesChannelId) !== false;
    }

    private function getMethodsWithFinancialWarning(string $salesChannelId): array
    {
        $methods = [
            'Billink',
            'klarnakp',
            'capayable',
            'afterpay'
        ];

        $withFinancialWarning = [];
        foreach ($methods as $method) {
            if (
                $this->settingsService->getSetting(
                    $method . "Financialwarning",
                    $salesChannelId
                ) !== false
            ) {
                $withFinancialWarning[] = $method;
            }
        }
        return $withFinancialWarning;
    }

    private function getBillinkPaymentType(CustomerEntity $customer): string
    {
        $billingAddress = $customer->getActiveBillingAddress();

        if ($billingAddress !== null && $billingAddress->getCompany() !== null) {
            return 'B2B';
        }
        return 'B2C';
    }

    public function addBuckarooToCart(CheckoutCartPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $struct             = new BuckarooStruct();

        $struct->assign([
            'applepayHostedPaymentPage' =>
                $this->settingsService->getSetting('applepayHostedPaymentPage', $salesChannelId) === 1,
            'showPaypalExpress'         => $this->showPaypalExpress($salesChannelId, 'cart'),
            'showIdealFastCheckout'     => $this->showIdealFastCheckout($salesChannelId, 'cart'),
            'paypalMerchantId'          => $this->getPaypalExpressMerchantId($salesChannelId),
            'applePayMerchantId'        => $this->getAppleMerchantId($salesChannelId),
            'websiteKey'                => $this->settingsService->getSetting('websiteKey', $salesChannelId),
            'showApplePay'              => $this->settingsService->getSetting('applepayShowCart', $salesChannelId) == 1,
            'idealFastCheckoutLogo'     => $this->getIdealFastCheckoutLogo($salesChannelId)
        ]);

        $event->getPage()->addExtension(
            BuckarooStruct::EXTENSION_NAME,
            $struct
        );
    }

    /**
     * @param array<mixed> $issuers
     * @param string|null $lastUsedIssuer
     * @param string $name
     * @return string
     */
    private function getPaymentMethodName(array $issuers, ?string $lastUsedIssuer, string $name = ''): string
    {
        foreach ($issuers as $issuer) {
            if (
                is_array($issuer) &&
                isset($issuer['code']) &&
                isset($issuer['name']) &&
                $issuer['code'] === $lastUsedIssuer
            ) {
                $issuerName = $issuer['name'];
                if (is_string($issuerName)) {
                    return $name == '' ? $issuerName : $name . ' (' . $issuerName . ')';
                }
            }
        }
        return $name;
    }

    private function removePaymentMethod(
        PaymentMethodCollection $paymentMethods,
        string $paymentMethodId
    ): PaymentMethodCollection {
        return $paymentMethods->filter(
            static function (PaymentMethodEntity $paymentMethod) use ($paymentMethodId) {
                return $paymentMethod->getId() !== $paymentMethodId;
            }
        );
    }
    public function addBuckarooToProductPage(ProductPageLoadedEvent $event): void
    {
        $struct = new BuckarooStruct();

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $struct->assign([
            'applepayShowProduct'       =>
                $this->settingsService->getSetting('applepayShowProduct', $salesChannelId) == 1,
            'applepayHostedPaymentPage' =>
                $this->settingsService->getSetting('applepayHostedPaymentPage', $salesChannelId) === 1,
            'showPaypalExpress' => $this->showPaypalExpress($salesChannelId),
            'showIdealFastCheckout' => $this->showIdealFastCheckout($salesChannelId),
            'paypalMerchantId' => $this->getPaypalExpressMerchantId($salesChannelId),
            'applePayMerchantId' => $this->getAppleMerchantId($salesChannelId),
            'websiteKey' => $this->settingsService->getSetting('websiteKey', $salesChannelId),
            'idealFastCheckoutLogo' => $this->getIdealFastCheckoutLogo($salesChannelId)
        ]);

        $event->getPage()->addExtension(
            BuckarooStruct::EXTENSION_NAME,
            $struct
        );
    }
    protected function showPaypalExpress(string $salesChannelId, string $page = 'product'): bool
    {
        $locations = $this->settingsService->getSetting('paypalExpresslocation', $salesChannelId);
        return is_array($locations) &&
            in_array($page, $locations) &&
            $this->getPaypalExpressMerchantId($salesChannelId) != null;
    }
    protected function getPaypalExpressMerchantId(string $salesChannelId): ?string
    {
        $merchantId =  $this->settingsService->getSetting('paypalExpressmerchantid', $salesChannelId);
        if ($merchantId !== null && is_scalar($merchantId)) {
            return (string)$merchantId;
        }
        return null;
    }
    protected function showIdealFastCheckout(string $salesChannelId, string $page = 'product'): bool
    {
        if (
            $this->settingsService->getSetting('idealEnabled', $salesChannelId) &&
            $this->settingsService->getSetting('idealFastCheckoutEnabled', $salesChannelId)
        ) {
            $locations = $this->settingsService->getSetting('idealFastCheckoutVisibility', $salesChannelId);
            return is_array($locations) &&
                in_array($page, $locations);
        } else {/**/
            return false;
        }
    }
    protected function getIdealFastCheckoutLogo(string $salesChannelId): ?string
    {
        $settings = $this->settingsService->getSetting('idealFastCheckoutLogoScheme', $salesChannelId);

        return is_string($settings) ? $settings : null;
    }
    protected function getAppleMerchantId(string $salesChannelId): ?string
    {
        $merchantId =  $this->settingsService->getSetting('guid', $salesChannelId);
        if ($merchantId !== null && is_scalar($merchantId)) {
            return (string)$merchantId;
        }
        return null;
    }
    protected function getIdealRenderMode(string $salesChannelId = null): int
    {
        $mode = $this->settingsService->getSetting('idealRenderMode', $salesChannelId);

        if ($mode !== null && is_scalar($mode)) {
            return (int)$mode;
        }

        return 0;
    }

    protected function getBuckarooFeeLabel(
        string $buckarooKey,
        CurrencyEntity $currency,
        string $salesChannelId = null,
        string $label = null
    ): string {
        if ($label === null) {
            $label = $this->settingsService->getSettingAsString($buckarooKey . 'Label', $salesChannelId);
        }

        if (
            $buckarooKey === 'capayable' &&
            $this->settingsService->getSetting('capayableVersion', $salesChannelId) === 'v2' &&
            $label === In3::DEFAULT_NAME
        ) {
            $label = In3::V2_NAME;
        }

        if ($buckarooFee = (string)$this->settingsService->getBuckarooFee($buckarooKey, $salesChannelId)) {
            $label .= ' +' . $currency->getSymbol() . $buckarooFee;
        }
        return $label;
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent|AccountEditOrderPageLoadedEvent $event
     *
     * @return CurrencyEntity
     */
    public function getCurrency($event): CurrencyEntity
    {
        if ($event instanceof AccountEditOrderPageLoadedEvent) {
            $currency = $event->getPage()->getOrder()->getCurrency();
            if ($currency !== null) {
                return $currency;
            }
        }
        return $event->getSalesChannelContext()->getCurrency();
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
        $transactions =  $transactions = $event->getPage()->getOrder()->getTransactions();

        if ($transactions === null) {
            return;
        }

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity|null */
        $transaction = $transactions->last();

        if ($transaction === null) {
            return;
        }

        $paymentMethod = $transaction->getPaymentMethod();
        if ($paymentMethod === null) {
            return;
        }

        $stateMachine = $transaction->getStateMachineState();
        if ($stateMachine === null) {
            return;
        }
        $session = $event->getRequest()->getSession();

        if (
            strpos($paymentMethod->getHandlerIdentifier(), 'Buckaroo\Shopware6\Handlers') !== false &&
            $stateMachine->getTechnicalName() === 'in_progress' &&
            method_exists($session, 'getFlashBag')
        ) {
            $session->getFlashBag()->add('success', $this->translator->trans('buckaroo.messages.return791'));
        }
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


        if ($event instanceof AccountEditOrderPageLoadedEvent) {
            $order = $event->getPage()->getOrder();

            $billingAddress = $order->getBillingAddress();
            if ($billingAddress !== null) {
                $billingCompany = $billingAddress->getCompany();
            }

            if ($order->getDeliveries() !== null) {
                /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity */
                $shippingAddress = $order->getDeliveries()->getShippingAddress()->first();
                if ($shippingAddress !== null) {
                    $shippingCompany = $shippingAddress->getCompany();
                }
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
        if (
            $isStrictB2B &&
            $this->isCompanyEmpty($billingCompany) &&
            $this->isCompanyEmpty($shippingCompany)
        ) {
            return false;
        }

        return true;
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
     * Can display phone number if required
     *
     * @param CustomerEntity $customer
     *
     * @return boolean
     */
    private function canShowPhone(CustomerEntity $customer): bool
    {
        $billingAddress = $customer->getActiveBillingAddress();
        $shippingAddress = $customer->getActiveShippingAddress();

        return $this->isPhoneEmpty($billingAddress) || $this->isPhoneEmpty($shippingAddress);
    }

    /**
     * Check if phone number is empty
     *
     * @param CustomerAddressEntity|null $address
     *
     * @return boolean
     */
    private function isPhoneEmpty(CustomerAddressEntity $address = null): bool
    {
        if ($address === null) {
            return true;
        }

        return $address->getPhoneNumber() === null || strlen(trim($address->getPhoneNumber())) === 0;
    }
}
