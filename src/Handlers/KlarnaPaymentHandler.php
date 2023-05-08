<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Klarna;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class KlarnaPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = Klarna::class;
    
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
            $this->getBillingData($order, $dataBag),
            $this->getShippingData($order, $dataBag),
            $this->getArticles($order, $paymentCode)
        );
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
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             =>  $this->getBirthDate($dataBag),
                    'gender'                =>  $dataBag->get('buckaroo_klarna_gender', 'male')
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
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             =>  $this->getBirthDate($dataBag),
                    'gender'                =>  $dataBag->get('buckaroo_klarna_gender', 'male')
                ],
                'address' => [
                    'street'                => $this->formatRequestParamService->getStreet($address, $streetParts),
                    'houseNumber'           => $this->formatRequestParamService->getHouseNumber($address, $streetParts),
                    'houseNumberAdditional' => $this->formatRequestParamService->getAdditionalHouseNumber($address, $streetParts),
                    'zipcode'               =>  $address->getZipcode(),
                    'city'                  =>  $address->getCity(),
                    'country'               =>  $address->getCountry()->getIso()
                ],
                'email'         => $order->getOrderCustomer()->getEmail()
            ],
        ];
    }

    private function getPhone(RequestDataBag $dataBag, OrderAddressEntity $address): string
    {
        return (string)$dataBag->get('buckaroo_klarna_phone', $address->getPhoneNumber());
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
     * Get birth date
     *
     * @param RequestDataBag $dataBag
     *
     * @return null|string|bool
     */
    private function getBirthDate(RequestDataBag $dataBag)
    {
        if ($dataBag->has('buckaroo_klarna_DoB')) {
            return @date(
                'd/m/Y',
                strtotime($dataBag->get('buckaroo_klarna_DoB'))
            );
        }
    }


}
