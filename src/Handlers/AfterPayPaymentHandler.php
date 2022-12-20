<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Handlers\AfterPayOld;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\AfterPay;
use Buckaroo\Resources\Constants\RecipientCategory;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class AfterPayPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = AfterPay::class;


    public const CUSTOMER_TYPE_B2C = 'b2c';
    public const CUSTOMER_TYPE_B2B = 'b2b';
    public const CUSTOMER_TYPE_BOTH = 'both';

    /**
     * @var \Buckaroo\Shopware6\Handlers\AfterPayOld
     */
    protected $afterPayOld;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        AfterPayOld $afterPayOld
    ) {
        parent::__construct($asyncPaymentService);
        $this->afterPayOld = $afterPayOld;
    }

    /**
     * Get parameters for specific payment method
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array
     */
    protected function getMethodPayload(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        $order = $transaction->getOrder();

        if ($this->getSetting('afterpayEnabledold') === true) {
           return $this->afterPayOld->buildPayParameters(
                $order,
                $salesChannelContext,
                $dataBag,
                $paymentCode
            );
        }

        return array_merge_recursive(
            $this->getBillingData($order, $dataBag, $salesChannelContext->getSalesChannelId()),
            $this->getShippingData($order, $dataBag, $salesChannelContext->getSalesChannelId()),
            $this->getArticles($order, $paymentCode)
        );
    }


    protected function getBillingData(
        OrderEntity $order,
        RequestDataBag $dataBag,
        string $salesChannelContextId
    ): array {
        $address = $order->getBillingAddress();
        $customer = $order->getOrderCustomer();

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());

        $data = [
            'billing' => [
                'recipient' => [
                    'category'              =>  $this->getCategory($address, $salesChannelContextId),
                    'careOf'                =>  $this->getCareOf($address),
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                ],
                'address' => [
                    'street'                => $this->formatRequestParamService->getStreet($address, $streetParts),
                    'houseNumber'           => $this->formatRequestParamService->getHouseNumber($address, $streetParts),
                    'houseNumberAdditional' => $this->formatRequestParamService->getAdditionalHouseNumber($address, $streetParts),
                    'zipcode'               =>  $address->getZipcode(),
                    'city'                  =>  $address->getCity(),
                    'country'               =>  $address->getCountry()->getIso()
                ],
                'phone' => [
                    'mobile'        => $this->getPhone($dataBag, $address),
                ],
                'email'         => $customer->getEmail()
            ]
        ];
        return array_merge_recursive(
            $data,
            $this->getCompany($address, $dataBag, $salesChannelContextId),
            $this->getBirthDayData($address, $dataBag)
        );

    }

    protected function getShippingData(
        OrderEntity $order,
        RequestDataBag $dataBag,
        string $salesChannelContextId
    ): array {
        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
        $address = $order->getDeliveries()->getShippingAddress()->first();
        if ($address === null) {
            $address = $order->getBillingAddress();
        }

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());
        $data = [
            'shipping' => [
                'recipient' => [
                    'category'              =>  $this->getCategory($address, $salesChannelContextId),
                    'careOf'                =>  $this->getCareOf($address),
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                ],
                'address' => [
                    'street'                => $this->formatRequestParamService->getStreet($address, $streetParts),
                    'houseNumber'           => $this->formatRequestParamService->getHouseNumber($address, $streetParts),
                    'houseNumberAdditional' => $this->formatRequestParamService->getAdditionalHouseNumber($address, $streetParts),
                    'zipcode'               =>  $address->getZipcode(),
                    'city'                  =>  $address->getCity(),
                    'country'               =>  $address->getCountry()->getIso()
                ],
            ],
        ];
        return array_merge_recursive(
            $data,
            $this->getCompany($address, $dataBag, $salesChannelContextId, 'shipping'),
            $this->getBirthDayData($address, $dataBag, 'shipping')
        );
    }

    

   
    private function getArticles(OrderEntity $order, string $paymentCode): array
    {
        $lines = $this->getOrderLinesArray($order, $paymentCode);

        $articles = [];

        foreach ($lines as $item) {
            $articles[] = [
                'identifier'        => $item['sku'],
                'description'       => $item['name'],
                'quantity'          => $item['quantity'],
                'price'             => $item['unitPrice']['value'],
                'vatPercentage'     => $item['vatRate'],
            ];
        }
        return [
            'articles' => $articles
        ];
    }

    
    protected function getCompany(
        OrderAddressEntity $address,
        RequestDataBag $dataBag,
        string $salesChannelContextId,
        string $type = 'billing'
        ): array
    {
        if (
            $this->isCustomerB2B($salesChannelContextId) &&
            $address->getCountry()->getIso() === 'NL' &&
            !$this->isCompanyEmpty($address->getCompany())
        ) {

            return [
                $type => [
                    'recipient'        => [
                        'companyName'   => $address->getCompany(),
                        'chamberOfCommerce' => $dataBag->get('buckaroo_afterpay_Coc'),
                    ]
                ]
            ];
        }
        return [];
    }

    protected function getBirthDayData(
        OrderAddressEntity $address,
        RequestDataBag $dataBag,
        string $type = 'billing'
    ): array
    {
        if (in_array($address->getCountry()->getIso(), ['NL', 'BE'])) {
            return [
                $type => [
                    'recipient' => [
                        'birthDate' => $this->getBirthDate($dataBag),
                    ]
                ]
            ];
        }
        return [];
    }

    private function getPhone(RequestDataBag $dataBag, OrderAddressEntity $address): string
    {
        return (string)$dataBag->get('buckaroo_afterpay_phone', $address->getPhoneNumber());
    }

    /**
     * Get  careOf
     *
     * @param OrderAddressEntity $address
     *
     * @return string
     */
    private function getCareOf(OrderAddressEntity $address): string
    {
        if (
            $address->getCompany() !== null &&
            !empty(trim($address->getCompany()))
        ) {
            return $address->getCompany();
        }

        return $address->getFirstName() . " " . $address->getLastName();
    }

    /**
     * Get type of request b2b or b2c
     *
     * @param OrderAddressEntity $address
     *
     * @return string
     */
    private function getCategory(OrderAddressEntity $address, string $salesChannelContextId): string
    {
        if (
            $this->isCustomerB2B($salesChannelContextId) &&
            $address->getCountry()->getIso() === 'NL' &&
            !$this->isCompanyEmpty($address->getCompany())
        ) {
            return RecipientCategory::COMPANY;
        }
        return RecipientCategory::PERSON;
    }

    /**
     * Get birth date
     *
     * @param RequestDataBag $dataBag
     *
     * @return null|string|bool
     */
    private function getBirthDate(RequestDataBag $dataBag)
    {
        if ($dataBag->has('buckaroo_afterpay_DoB')) {
            return @date(
                "d-m-Y",
                strtotime($dataBag->get('buckaroo_afterpay_DoB'))
            );
        }
    }

    public function isCustomerB2B($salesChannelId = null)
    {
        return $this->getSetting('afterpayCustomerType', $salesChannelId) !== self::CUSTOMER_TYPE_B2C;
    }
   
    /**
     * Check if company is empty
     *
     * @param string $company
     *
     * @return boolean
     */
    public function isCompanyEmpty(string $company = null)
    {
        return null === $company || strlen(trim($company)) === 0;
    }
}
