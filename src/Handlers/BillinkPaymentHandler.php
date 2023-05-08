<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Billink;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class BillinkPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = Billink::class;


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
        return array_merge_recursive(
            $this->getVatNumber($dataBag),
            $this->getCoc($dataBag),
            $this->getBillingData($order, $dataBag),
            $this->getShippingData($order, $dataBag),
            $this->getArticles($order, $paymentCode)
        );
    }

    /**
     * Get method action for specific payment method
     *
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return string
     */
    protected function getMethodAction(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): string {
        if (
            $this->getSetting(
                'BillinkMode',
                $salesChannelContext->getSalesChannelId()
            ) == 'authorize'
        ) {
            return 'authorize';
        }
        return 'pay';
    }

    protected function getBillingData(
        OrderEntity $order,
        RequestDataBag $dataBag
    ): array {
        $address = $order->getBillingAddress();
        $customer = $order->getOrderCustomer();

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());

        return [
            'billing' => [
                'recipient' => [
                    'category'              =>  $this->getCategory($address),
                    'careOf'                =>  $this->getCareOf($address),
                    'initials'              =>  $this->getInitials($address->getFirstName()),
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             =>  $this->getBirthDate($dataBag),
                    'salutation'            =>  $dataBag->get('buckaroo_billink_gender')
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


    }

    protected function getShippingData(
        OrderEntity $order,
        RequestDataBag $dataBag
    ): array {
        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
        $address = $order->getDeliveries()->getShippingAddress()->first();
        if ($address === null) {
            $address = $order->getBillingAddress();
        }

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());
        return [
            'shipping' => [
                'recipient' => [
                    'category'              =>  $this->getCategory($address),
                    'careOf'                =>  $this->getCareOf($address),
                    'initials'              =>  $this->getInitials($address->getFirstName()),
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             =>  $this->getBirthDate($dataBag),
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
    }

    /**
     * Get vat number
     *
     * @param RequestDataBag $dataBag
     *
     * @return array
     */
    protected function getVatNumber(RequestDataBag $dataBag): array
    {
        $vatNumber = $dataBag->get('buckaroo_VATNumber');
        if (
            is_string($vatNumber) &&
            !empty(trim($vatNumber))
        ) {
            return ['vATNumber' => $vatNumber];
        }
        return [];
    }

    /**
     * Get chamber of commerce number
     *
     * @param RequestDataBag $dataBag
     *
     * @return array
     */
    protected function getCoc(RequestDataBag $dataBag): array
    {
        if (
            $dataBag->has('buckaroo_ChamberOfCommerce') &&
            is_string($dataBag->get('buckaroo_ChamberOfCommerce'))
        ) {
            return [
                'billing' => [
                    'recipient' => [
                        'chamberOfCommerce' => $dataBag->get('buckaroo_ChamberOfCommerce')
                    ]
                ]
            ];
        }
        return [];
    }

    private function getPhone(RequestDataBag $dataBag, OrderAddressEntity $address): string
    {
        return (string)$dataBag->get('buckaroo_billink_phone', $address->getPhoneNumber());
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
    private function getCategory(OrderAddressEntity $address): string
    {
        if (
            $address->getCompany() !== null &&
            !empty(trim($address->getCompany()))
        ) {
            return 'B2B';
        }
        return 'B2C';
    }

    /**
     *
     * @param string $name
     *
     * @return string
     */
    private function getInitials(string $name): string
    {
        return strtoupper(substr($name, 0, 1));
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
        if ($dataBag->has('buckaroo_billink_DoB')) {
            return @date(
                "d-m-Y",
                strtotime($dataBag->get('buckaroo_billink_DoB'))
            );
        }
    }
}
