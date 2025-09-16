<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Klarna;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class KlarnaPaymentHandler extends PaymentHandler
{
    protected string $paymentClass = Klarna::class;

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
        return array_merge_recursive(
            $this->getBillingData($order, $dataBag),
            $this->getShippingData($order, $dataBag),
            $this->getArticles($order, $paymentCode)
        );
    }
    /**
     * Get billing address data
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     *
     * @return array<mixed>
     */
    protected function getBillingData(
        OrderEntity $order,
        RequestDataBag $dataBag
    ): array {
        $address = $this->asyncPaymentService->getBillingAddress($order);
        $customer = $this->asyncPaymentService->getCustomer($order);

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());

        return [
            'billing' => [
                'recipient' => [
                    'category'              =>  $this->getCategory($address),
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             =>  $this->getBirthDate($dataBag),
                    'gender'                =>  $dataBag->get('buckaroo_klarna_gender', 'male')
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
    }

    /**
     * Get shipping address data
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     *
     * @return array<mixed>
     */
    protected function getShippingData(
        OrderEntity $order,
        RequestDataBag $dataBag
    ): array {
        $address = $this->asyncPaymentService->getShippingAddress($order);

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());
        return [
            'shipping' => [
                'recipient' => [
                    'category'              =>  $this->getCategory($address),
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             =>  $this->getBirthDate($dataBag),
                    'gender'                =>  $dataBag->get('buckaroo_klarna_gender', 'male')
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
                'email'         =>  $this->asyncPaymentService->getCustomer($order)->getEmail()
            ],
        ];
    }

    private function getPhone(RequestDataBag $dataBag, OrderAddressEntity $address): string
    {
        $phone = $dataBag->get('buckaroo_klarna_phone', $address->getPhoneNumber());

        if (is_scalar($phone)) {
            return (string)$phone;
        }
        return '';
    }

    /**
     * Get article data
     *
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
                'price'             => $item['unitPrice']['value'],
                'vatPercentage'     => $item['vatRate'],
            ];
        }
        return [
            'articles' => $articles
        ];
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
        if ($address->getCompany() !== null &&
            !empty(trim($address->getCompany()))
        ) {
            return 'B2B';
        }
        return 'B2C';
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
        if (!$dataBag->has('buckaroo_klarna_DoB')) {
            return null;
        }

        $dateString = $dataBag->get('buckaroo_klarna_DoB');
        if (!is_scalar($dateString)) {
            return null;
        }
        $date = strtotime((string)$dateString);
        if ($date === false) {
            return null;
        }

        return @date("d/m/Y", $date);
    }
}
