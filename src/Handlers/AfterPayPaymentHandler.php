<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Handlers\AfterPayOld;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\AfterPay;
use Buckaroo\Resources\Constants\RecipientCategory;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\CaptureService;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

    /** @inheritDoc */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        if ($this->isAuthorization($salesChannelContext->getSalesChannelId())) {
            $this->asyncPaymentService
                ->checkoutHelper
                ->appendCustomFields(
                    $transaction->getOrder()->getId(),
                    [
                        CaptureService::ORDER_IS_AUTHORIZED => true,
                    ],
                    $salesChannelContext->getContext()
                );
        }
        return parent::pay($transaction, $dataBag, $salesChannelContext);
    }

    /**
     * Get parameters for specific payment method
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        if ($this->getSetting('afterpayEnabledold') === true) {
            return $this->afterPayOld->buildPayParameters(
                $order,
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

    /** @inheritDoc */
    protected function getMethodAction(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): string {
        if ($this->isAuthorization($salesChannelContext->getSalesChannelId())) {
            return 'Authorize';
        }
        return parent::getMethodAction($dataBag, $salesChannelContext, $paymentCode);
    }

    private function isAuthorization(string $salesChannelContextId): bool
    {
        return $this->getSetting('afterpayAuthorize', $salesChannelContextId) === true;
    }

    /**
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param string $salesChannelContextId
     *
     * @return array<mixed>
     */
    protected function getBillingData(
        OrderEntity $order,
        RequestDataBag $dataBag,
        string $salesChannelContextId
    ): array {
        $address = $this->asyncPaymentService->getBillingAddress($order);
        $customer = $this->asyncPaymentService->getCustomer($order);

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
                    'houseNumberAdditional' => $this->formatRequestParamService
                        ->getAdditionalHouseNumber(
                            $address,
                            $streetParts
                        ),
                    'zipcode'               =>  $address->getZipcode(),
                    'city'                  =>  $address->getCity(),
                    'country'               =>  $this->asyncPaymentService->getCountry($address)->getIso()
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

    /**
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param string $salesChannelContextId
     *
     * @return array<mixed>
     */
    protected function getShippingData(
        OrderEntity $order,
        RequestDataBag $dataBag,
        string $salesChannelContextId
    ): array {
        $address = $this->asyncPaymentService->getShippingAddress($order);

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
                    'houseNumberAdditional' => $this->formatRequestParamService
                        ->getAdditionalHouseNumber(
                            $address,
                            $streetParts
                        ),
                    'zipcode'               =>  $address->getZipcode(),
                    'city'                  =>  $address->getCity(),
                    'country'               => $this->asyncPaymentService->getCountry($address)->getIso()
                ],
            ],
        ];
        return array_merge_recursive(
            $data,
            $this->getCompany($address, $dataBag, $salesChannelContextId, 'shipping'),
            $this->getBirthDayData($address, $dataBag, 'shipping')
        );
    }

    /**
     * @param OrderEntity $order
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    private function getArticles(OrderEntity $order, string $paymentCode): array
    {
        $lines = $this->getOrderLinesArray($order, $paymentCode);

        $articles = [];

        foreach ($lines as $item) {
            if (!is_array($item)) {
                continue;
            }
            $articles[] = [
                'identifier'        => $item['sku'],
                'description'       => $item['name'],
                'quantity'          => $item['quantity'],
                'price'             => $item['unitPrice'],
                'vatPercentage'     => $item['vatRate'],
            ];
        }
        return [
            'articles' => $articles
        ];
    }

    /**
     * @param OrderAddressEntity $address
     * @param RequestDataBag $dataBag
     * @param string $salesChannelContextId
     * @param string $type
     *
     * @return array<mixed>
     */
    protected function getCompany(
        OrderAddressEntity $address,
        RequestDataBag $dataBag,
        string $salesChannelContextId,
        string $type = 'billing'
    ): array {
        if (
            $this->isCustomerB2B($salesChannelContextId) &&
            $this->asyncPaymentService->getCountry($address)->getIso() === 'NL' &&
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

    /**
     * @param OrderAddressEntity $address
     * @param RequestDataBag $dataBag
     * @param string $type
     *
     * @return array<mixed>
     */
    protected function getBirthDayData(
        OrderAddressEntity $address,
        RequestDataBag $dataBag,
        string $type = 'billing'
    ): array {
        if (in_array($this->asyncPaymentService->getCountry($address)->getIso(), ['NL', 'BE'])) {
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
        $phone = $dataBag->get('buckaroo_afterpay_phone', $address->getPhoneNumber());
        if (is_scalar($phone)) {
            return (string)$phone;
        }
        return '';
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
            $this->asyncPaymentService->getCountry($address)->getIso() === 'NL' &&
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
     * @return null|string
     */
    private function getBirthDate(RequestDataBag $dataBag)
    {
        $dateString = $dataBag->get('buckaroo_afterpay_DoB');
        if (!is_scalar($dateString)) {
            return null;
        }
        $date = strtotime((string)$dateString);
        if ($date === false) {
            return null;
        }

        return @date("d-m-Y", $date);
    }

    public function isCustomerB2B(string $salesChannelId = null): bool
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
    public function isCompanyEmpty(string $company = null): bool
    {
        return null === $company || strlen(trim($company)) === 0;
    }
}
