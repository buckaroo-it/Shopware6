<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Klarna;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class KlarnaPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Klarna::class;
    public const ARTICLE_TYPE_GENERAL = 'General';
    public const ARTICLE_TYPE_HANDLING_FEE = 'HandlingFee';
    public const ARTICLE_TYPE_SHIPMENT_FEE = 'ShipmentFee';

    /**
     * Get method action for specific payment method
     *
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext|null $salesChannelContext
     * @param string|null $paymentCode
     *
     * @return string
     */
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        return 'reserve';
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
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        return array_merge_recursive(
            [
                'operatingCountry' => $this->asyncPaymentService->getCountry(
                    $this->asyncPaymentService->getBillingAddress($order)
                )->getIso(),
                'pno'    => $this->getBirthDate($dataBag),
                'gender' => $this->getGender($order, $dataBag),
            ],
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

            $unitPrice = $item['unitPrice'] ?? [];
            $price = is_array($unitPrice) ? ($unitPrice['value'] ?? 0) : 0;

            // Klarna rejects articles with a zero unit price (e.g. free shipping).
            // Items with a negative price (discounts) are still included.
            if ((float)$price === 0.0) {
                continue;
            }

            $articles[] = [
                'identifier'    => (string)($item['sku'] ?? $item['id'] ?? ''),
                'description'   => (string)($item['name'] ?? ''),
                'quantity'      => (int)($item['quantity'] ?? 1),
                'price'         => (float)$price,
                'vatPercentage' => (string)($item['vatRate'] ?? '0.00'),
                'type'          => $this->getArticleType($item),
            ];
        }
        return [
            'articles' => $articles
        ];
    }

    /**
     * Determine the Klarna article type from an order line item array.
     * Uses the `type` field (set by FormatRequestParamService) as the primary
     * indicator, with a fallback to the legacy `sku`-based check.
     *
     * @param array<mixed> $article
     *
     * @return string
     */
    private function getArticleType(array $article): string
    {
        $lineType = $article['type'] ?? '';

        if ($lineType === 'Shipping') {
            return self::ARTICLE_TYPE_SHIPMENT_FEE;
        }

        if ($lineType === 'BuckarooFee' || ($article['sku'] ?? '') === 'BuckarooFee') {
            return self::ARTICLE_TYPE_HANDLING_FEE;
        }

        return self::ARTICLE_TYPE_GENERAL;
    }



    /**
     * Resolve the customer gender for the Klarna Reserve request.
     *
     * Klarna requires gender (1 = male, 2 = female) for PNO-based flows.
     * Priority:
     *   1. Explicit value from checkout dataBag (`buckaroo_klarna_gender`)
     *   2. Derived from the order customer's salutation key
     *   3. Defaults to 1 (male) when not determinable
     *
     * @param OrderEntity    $order
     * @param RequestDataBag $dataBag
     *
     * @return int
     */
    private function getGender(OrderEntity $order, RequestDataBag $dataBag): int
    {
        $fromBag = $dataBag->get('buckaroo_klarna_gender');
        if (is_numeric($fromBag)) {
            return (int)$fromBag;
        }

        $customer = $order->getOrderCustomer();
        if ($customer !== null) {
            $salutation = $customer->getSalutation();
            if ($salutation !== null) {
                if ($salutation->getSalutationKey() === 'mr') {
                    return 1;
                }
                if ($salutation->getSalutationKey() === 'mrs') {
                    return 2;
                }
            }
        }

        return 1;
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
