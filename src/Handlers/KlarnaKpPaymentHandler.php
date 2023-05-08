<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\KlarnaKp;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class KlarnaKpPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = KlarnaKp::class;
    const KLARNAKP_ARTICLE_TYPE_GENERAL = 'General';
    const KLARNAKP_ARTICLE_TYPE_HANDLINGFEE = 'HandlingFee';
    const KLARNAKP_ARTICLE_TYPE_SHIPMENTFEE = 'ShipmentFee';


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
            [
                'operatingCountry'  => $order->getBillingAddress()->getCountry()->getIso(),
                'pno'               => $this->getBirthDate($dataBag),  
            ],
            $this->getBillingData($order, $dataBag),
            $this->getShippingData($order),
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
        return 'reserve';
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


    }

    protected function getShippingData(
        OrderEntity $order
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
                'email'         => $order->getOrderCustomer()->getEmail()
            ],
        ];
    }

    private function getPhone(RequestDataBag $dataBag, OrderAddressEntity $address): string
    {
        return (string)$dataBag->get('buckaroo_klarnakp_phone', $address->getPhoneNumber());
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
                'type'              => $this->getArticleType($item)
            ];
        }
        return [
            'articles' => $articles
        ];
    }

    private function getArticleType(array $article): string
    {
        if($article['sku'] === 'Shipping') {
            return self::KLARNAKP_ARTICLE_TYPE_SHIPMENTFEE;
        }
        return self::KLARNAKP_ARTICLE_TYPE_GENERAL;
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
        if ($dataBag->has('buckaroo_klarnakp_DoB')) {
            return gmdate(
                'dmY', 
                strtotime($dataBag->get('buckaroo_klarnakp_DoB'))
            );
        }
    }

}
