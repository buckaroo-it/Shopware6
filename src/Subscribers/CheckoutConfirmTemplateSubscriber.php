<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Subscribers;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Helpers\Helper;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Account\PaymentMethod\AccountPaymentMethodPageLoadedEvent;

class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{

    private $customerRepository;
    /** @var CheckoutHelper $checkoutHelper */
    public $checkoutHelper;

    /**
     * @var SalesChannelRepositoryInterface
     */
    private $paymentMethodRepository;

    /**
     * @var array
     */
    protected $issuers = [
        [
            'name' => 'ABN AMRO',
            'code' => 'ABNANL2A',
        ],
        [
            'name' => 'ASN Bank',
            'code' => 'ASNBNL21',
        ],
        [
            'name' => 'Bunq Bank',
            'code' => 'BUNQNL2A',
        ],
        [
            'name' => 'ING',
            'code' => 'INGBNL2A',
        ],
        [
            'name' => 'Knab Bank',
            'code' => 'KNABNL2H',
        ],
        [
            'name' => 'Rabobank',
            'code' => 'RABONL2U',
        ],
        [
            'name' => 'RegioBank',
            'code' => 'RBRBNL21',
        ],
        [
            'name' => 'SNS Bank',
            'code' => 'SNSBNL2A',
        ],
        [
            'name' => 'Triodos Bank',
            'code' => 'TRIONL2U',
        ],
        [
            'name' => 'Van Lanschot',
            'code' => 'FVLBNL22',
        ],
        [
            'name' => 'Revolut',
            'code' => 'REVOLT21',
        ],
    ];

    protected $availableCreditcards = [
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

    protected  SettingsService $settingsService;
    protected UrlService $urlService;
    protected Session $session;
    protected TranslatorInterface $translator;
    /**
     * CheckoutConfirmTemplateSubscriber constructor.
     * @param Helper $helper
     * @param EntityRepositoryInterface $customerRepository
     * @param SalesChannelRepositoryInterface $paymentMethodRepository
     */
    public function __construct(
        EntityRepositoryInterface $customerRepository,
        SalesChannelRepositoryInterface $paymentMethodRepository,
        SettingsService $settingsService,
        UrlService $urlService,
        Session $session,
        TranslatorInterface $translator
    ) {
        $this->customerRepository      = $customerRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->session = $session;
        $this->translator = $translator;
    }

    /**
     * @return array
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
        foreach ($paymentMethods as $paymentMethod) {
            if(!isset($paymentMethod->getTranslated()['customFields']['buckaroo_key'])){continue;} 
            if($buckarooKey = $paymentMethod->getTranslated()['customFields']['buckaroo_key']) {
                if(!$this->settingsService->getEnabled($buckarooKey, $event->getSalesChannelContext()->getSalesChannelId())){
                    $paymentMethods = $this->removePaymentMethod($paymentMethods, $paymentMethod->getId());
                }

                if ($buckarooKey === 'payperemail' && $this->isPayPermMailDisabledInFrontend($event->getSalesChannelContext()->getSalesChannelId())) {
                    $paymentMethods = $this->removePaymentMethod($paymentMethods, $paymentMethod->getId());
                }

                if ($buckarooKey === 'afterpay' && !$this->canShowAfterpay($event)) {
                    $paymentMethods = $this->removePaymentMethod($paymentMethods, $paymentMethod->getId());
                }
            }
        }
        $event->getPage()->setPaymentMethods($paymentMethods);
    }

    public function isPayPermMailDisabledInFrontend($salesChannelId)
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

        $context = $event->getContext();
        $request  = $event->getRequest();
        $customer = $event->getSalesChannelContext()->getCustomer();
        $buckarooKey = isset($event->getSalesChannelContext()->getPaymentMethod()->getTranslated()['customFields']['buckaroo_key']) ? $event->getSalesChannelContext()->getPaymentMethod()->getTranslated()['customFields']['buckaroo_key'] : null;
        $currency = $this->getCurrency($event);

        if ($lastCreditcard = $request->get('creditcard')) {
            $this->customerRepository->upsert(
                [[
                    'id'           => $customer->getId(),
                    'customFields' => [
                        'last_used_creditcard' => $lastCreditcard,
                    ],
                ]],
                $event->getContext()
            );
            $customer = $this->getCustomer($customer->getId(), $event->getContext());
        }

        $struct             = new BuckarooStruct();
        $issuers            = $this->issuers;
        $idealRenderMode    = $this->getIdealRenderMode($salesChannelId); 
        $lastUsedCreditcard = 'visa';
        if($customFields = $customer->getCustomFields()){
            if (isset($customFields['last_used_creditcard'])) {
                $lastUsedCreditcard = $customFields['last_used_creditcard'];
            }
        }
        
        $creditcard = [];
        $allowedcreditcard = $this->settingsService->getSetting('allowedcreditcard', $salesChannelId);
        if (!empty($allowedcreditcard)){
            foreach ($allowedcreditcard as $value) {
                $creditcard[] = [
                    'name' => $this->getBuckarooFeeLabel(
                        'allowedcreditcard',
                        $currency,
                        $salesChannelId,
                        $this->availableCreditcards[$value],
                    ),
                    'code' => $value,
                ];
            }
        }

        $creditcards = [];
        $allowedcreditcards = $this->settingsService->getSetting('allowedcreditcards', $salesChannelId);
        if (!empty($allowedcreditcards)){
            foreach ($allowedcreditcards as $value) {
                $creditcards[] = [
                    'name' => $this->getBuckarooFeeLabel(
                        'allowedcreditcards',
                        $currency,
                        $salesChannelId,
                        $this->availableCreditcards[$value],
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
        $paymentMethods = $this->paymentMethodRepository->search($criteria, $event->getSalesChannelContext())->getEntities();
        foreach ($paymentMethods as $key => $paymentMethod) {
            $method = $paymentMethod->getTranslated();
            if (!empty($method['customFields']['buckaroo_key'])) {
                $buckaroo_key = $method['customFields']['buckaroo_key'];
                $paymentLabels[$buckaroo_key] = $this->getBuckarooFeeLabel(
                    $buckaroo_key,
                    $currency,
                    $salesChannelId
                );
            }
        }

        $backUrl = $this->urlService->getRestoreUrl();

        $struct->assign([
            'currency'                 => $currency->getIsoCode(),
            'issuers'                  => $issuers,
            'ideal_render_mode'        => $idealRenderMode,
            'payment_method_name_card' => $this->getPaymentMethodName($creditcard, $lastUsedCreditcard, ''),
            'creditcard'               => $creditcard,
            'creditcards'              => $creditcards,
            'last_used_creditcard'     => $lastUsedCreditcard,
            'payment_labels'           => $paymentLabels,
            'payment_media'            => $lastUsedCreditcard . '.png',
            'buckarooFee'              => $this->settingsService->getBuckarooFee($buckarooKey . 'Fee', $salesChannelId),
            'BillinkBusiness'          => $customer->getActiveBillingAddress() && $customer->getActiveBillingAddress()->getCompany() ? 'B2B' : 'B2C',
            'backLink'                 => $backUrl,
            'afterpay_customer_type'   => $this->settingsService->getSetting('afterpayCustomerType', $salesChannelId),
            'showPaypalExpress'        => $this->showPaypalExpress($salesChannelId, 'checkout'),
            'paypalMerchantId'         => $this->getPaypalExpressMerchantId($salesChannelId),
            'applePayMerchantId'         => $this->getAppleMerchantId($salesChannelId),
            'websiteKey'               => $this->settingsService->getSetting('websiteKey', $salesChannelId)
        ]);

        $event->getPage()->addExtension(
            BuckarooStruct::EXTENSION_NAME,
            $struct
        );
    }

    public function addBuckarooToCart(CheckoutCartPageLoadedEvent $event)
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $struct             = new BuckarooStruct();

        $struct->assign([
            'showPaypalExpress'        => $this->showPaypalExpress($salesChannelId, 'cart'),
            'paypalMerchantId'         => $this->getPaypalExpressMerchantId($salesChannelId),
            'applePayMerchantId'       => $this->getAppleMerchantId($salesChannelId),
            'websiteKey'               => $this->settingsService->getSetting('websiteKey', $salesChannelId),
            'showApplePay'         => $this->settingsService->getSetting('applepayShowCart', $salesChannelId) == 1
        ]);

        $event->getPage()->addExtension(
            BuckarooStruct::EXTENSION_NAME,
            $struct
        );
    }
    /**
     * @param string $customerId
     * @param Context $context
     * @return CustomerEntity
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function getCustomer(string $customerId, Context $context): CustomerEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('id', $customerId));

        /** @var \Shopware\Core\Checkout\Customer\CustomerEntity */
        $customer = $this->customerRepository->search($criteria, $context)->first();
        return $customer;
    }

    /**
     * @param array $issuers
     * @param string|null $lastUsedIssuer
     * @param name
     * @return string
     */
    private function getPaymentMethodName(array $issuers,  ? string $lastUsedIssuer, $name = '') : string
    {
        foreach ($issuers as $issuer) {
            if ($issuer['code'] === $lastUsedIssuer) {
                $issuerName = $issuer['name'];
                return $name == '' ? $issuerName : $name . ' (' . $issuerName . ')';
            }
        }
        return $name;
    }

    private function removePaymentMethod(PaymentMethodCollection $paymentMethods, string $paymentMethodId): PaymentMethodCollection
    {
        return $paymentMethods->filter(
            static function (PaymentMethodEntity $paymentMethod) use ($paymentMethodId) {
                return $paymentMethod->getId() !== $paymentMethodId;
            }
        );
    }
    public function addBuckarooToProductPage($event)
    {
        $struct = new BuckarooStruct();

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $struct->assign([
            'applepayShowProduct' => $this->settingsService->getSetting('applepayShowProduct', $salesChannelId) == 1,
            'showPaypalExpress' => $this->showPaypalExpress($salesChannelId),
            'paypalMerchantId' => $this->getPaypalExpressMerchantId($salesChannelId),
            'applePayMerchantId' => $this->getAppleMerchantId($salesChannelId),
            'websiteKey' => $this->settingsService->getSetting('websiteKey', $salesChannelId)
        ]);

        $event->getPage()->addExtension(
            BuckarooStruct::EXTENSION_NAME,
            $struct
        );
    }
    protected function showPaypalExpress(string $salesChannelId, $page = 'product')
    {
        $locations = $this->settingsService->getSetting('paypalExpresslocation', $salesChannelId);
        return is_array($locations) && in_array($page, $locations) && $this->getPaypalExpressMerchantId($salesChannelId) != null;
    }
    protected function getPaypalExpressMerchantId(string $salesChannelId)
    {
       return $this->settingsService->getSetting('paypalExpressmerchantid', $salesChannelId);
    }
    protected function getAppleMerchantId(string $salesChannelId)
    {
        return $this->settingsService->getSetting('guid', $salesChannelId);
    }
    protected function getIdealRenderMode(string $salesChannelId = null)
    {
        return $this->settingsService->getSetting('idealRenderMode', $salesChannelId);
    }
    
    protected function getBuckarooFeeLabel(
        string $buckarooKey,
        CurrencyEntity $currency,
        string $salesChannelId = null,
        string $label = null
    ): string
    {
        if($label === null) {
            $label = $this->settingsService->getSetting($buckarooKey . 'Label', $salesChannelId);
        }

        if($buckarooFee = $this->settingsService->getSetting($buckarooKey.'Fee', $salesChannelId)) {
            $label .= ' +' . $currency->getSymbol() . $buckarooFee;
        }
        return $label;
    }

    public function getCurrency($event): CurrencyEntity
    {
        if($event instanceof AccountEditOrderPageLoadedEvent) {
            $currency = $event->getPage()->getOrder()->getCurrency();
            if($currency !== null) {
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
    public function addInProgress(CheckoutFinishPageLoadedEvent $event)
    {
        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity */
        $transaction = $event->getPage()->getOrder()->getTransactions()->last();
        if(
            $transaction !== null &&
            $transaction->getPaymentMethod() !== null &&
            strpos($transaction->getPaymentMethod()->getHandlerIdentifier(), 'Buckaroo\Shopware6\Handlers') !== false &&
            $transaction->getStateMachineState() !== null &&
            $transaction->getStateMachineState()->getTechnicalName() === 'in_progress'
        ) {
            $this->session->getFlashBag()->add('success', $this->translator->trans('buckaroo.messages.return791'));
        }
    }

    /**
     * Check if we can display afterpay when b2b is enabled 
     *
     * @param AccountEditOrderPageLoadedEvent|CheckoutConfirmPageLoadedEvent $event
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


        if($event instanceof AccountEditOrderPageLoadedEvent) {
            $order = $event->getPage()->getOrder();

            $billingAddress =$order->getBillingAddress();
            if($billingAddress !== null) {
                $billingCompany = $billingAddress->getCompany();
            }

            if($order->getDeliveries() !== null) {
                /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity */
                $shippingAddress = $order->getDeliveries()->getShippingAddress()->first();
                if($shippingAddress !== null) {
                    $shippingCompany = $shippingAddress->getCompany();
                }
            }
        }

        if($event instanceof CheckoutConfirmPageLoadedEvent) {
            $customer = $event->getSalesChannelContext()->getCustomer();

            $billingAddress = $customer->getDefaultBillingAddress();

            if($billingAddress !== null) {
                $billingCompany = $billingAddress->getCompany();
            }

            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity */
            $shippingAddress = $customer->getDefaultShippingAddress();
            if($shippingAddress !== null) {
                $shippingCompany = $shippingAddress->getCompany();
            }
        }
        if(
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
}
