<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\KlarnaKp;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class KlarnaKpPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = KlarnaKp::class;
    public const ARTICLE_TYPE_GENERAL = 'General';
    public const ARTICLE_TYPE_HANDLING_FEE = 'HandlingFee';
    public const ARTICLE_TYPE_SHIPMENT_FEE = 'ShipmentFee';


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
            [
                'operatingCountry'  => $this->asyncPaymentService->getCountry(
                    $this->asyncPaymentService->getBillingAddress($order)
                )->getIso(),
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

    /**
     * Get billing data
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

        $houseAdditionalNumber = $this->formatRequestParamService
            ->getAdditionalHouseNumber(
                $address,
                $streetParts
            );

        return [
            'billing' => [
                'recipient' => [
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                ],
                'address' => [
                    'street'                => $this->formatRequestParamService->getStreet($address, $streetParts),
                    'houseNumber'           => $this->formatRequestParamService->getHouseNumber($address, $streetParts),
                    'houseNumberAdditional' => $houseAdditionalNumber,
                    'zipcode'               =>  $address->getZipcode(),
                    'city'                  =>  $address->getCity(),
                    'country'               =>   $this->asyncPaymentService->getCountry($address)->getIso()
                ],
                'phone' => [
                    'mobile'        => $this->getPhone($dataBag, $address),
                ],
                'email'         => $customer->getEmail()
            ]
        ];
    }

    /**
     * Get shipping data
     *
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    protected function getShippingData(
        OrderEntity $order
    ): array {
        $address = $this->asyncPaymentService->getShippingAddress($order);

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());

        $houseAdditionalNumber = $this->formatRequestParamService
            ->getAdditionalHouseNumber(
                $address,
                $streetParts
            );

        return [
            'shipping' => [
                'recipient' => [
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                ],
                'address' => [
                    'street'                => $this->formatRequestParamService->getStreet($address, $streetParts),
                    'houseNumber'           => $this->formatRequestParamService->getHouseNumber($address, $streetParts),
                    'houseNumberAdditional' => $houseAdditionalNumber,
                    'zipcode'               =>  $address->getZipcode(),
                    'city'                  =>  $address->getCity(),
                    'country'               =>  $this->asyncPaymentService->getCountry($address)->getIso()
                ],
                'email'         => $this->asyncPaymentService->getCustomer($order)->getEmail()
            ],
        ];
    }

    private function getPhone(RequestDataBag $dataBag, OrderAddressEntity $address): string
    {
        $phone = $dataBag->get('buckaroo_klarnakp_phone', $address->getPhoneNumber());

        if (!is_scalar($phone)) {
            return '';
        }
        return (string)$phone;
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
                'type'              => $this->getArticleType($item)
            ];
        }
        return [
            'articles' => $articles
        ];
    }

    /**
     * @param array<mixed> $article
     *
     * @return string
     */
    private function getArticleType(array $article): string
    {
        if ($article['sku'] === 'Shipping') {
            return self::ARTICLE_TYPE_SHIPMENT_FEE;
        }

        if ($article['sku'] === 'BuckarooFee') {
            return self::ARTICLE_TYPE_HANDLING_FEE;
        }

        return self::ARTICLE_TYPE_GENERAL;
    }


    /**
     * Get birth date
     *
     * @param RequestDataBag $dataBag
     *
     * @return null|string
     */
    private function getBirthDate(RequestDataBag $dataBag): ?string
    {
        if (!$dataBag->has('buckaroo_klarnakp_DoB')) {
            return null;
        }

        $dateString = $dataBag->get('buckaroo_klarnakp_DoB');
        if (!is_scalar($dateString)) {
            return null;
        }
        $date = strtotime((string)$dateString);
        if ($date === false) {
            return null;
        }

        return @date("dmY", $date);
    }
}
