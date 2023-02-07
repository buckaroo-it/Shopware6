<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class AfterPayOld
{
    protected SettingsService $settingsService;

    protected FormatRequestParamService $formatRequestParamService;

    public function __construct(
        SettingsService $settingsService,
        FormatRequestParamService $formatRequestParamService
    ) {
        $this->settingsService = $settingsService;
        $this->formatRequestParamService = $formatRequestParamService;
    }

    /**
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @param RequestDataBag $dataBag
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    public function buildPayParameters(
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        RequestDataBag $dataBag,
        string $paymentCode
    ): array {

        return array_merge_recursive(
            [
                'customerIPAddress' => (Request::createFromGlobals())->getClientIp()
            ],
            $this->getBillingData($order, $dataBag),
            $this->getShippingData($order, $dataBag),
            $this->getArticles($order, $paymentCode)
        );
    }

    /**
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     *
     * @return array<mixed>
     */
    protected function getBillingData(
        OrderEntity $order,
        RequestDataBag $dataBag
    ): array {
        $address = $order->getBillingAddress();
        $customer = $order->getOrderCustomer();

        if ($address === null || $customer === null) {
            throw new \InvalidArgumentException('Address and customer cannot be null');
        }

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());

        if ($address->getCountry() === null) {
            throw new \InvalidArgumentException('Address country cannot be null');
        }

        return [
            'billing' => [
                'recipient' => [
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             => $this->getBirthDate($dataBag),
                    'initials'              => strtoupper(substr($address->getFirstName(), 0, 1)),
                    'culture'               => $address->getCountry()->getIso()
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
                    'country'               =>  $address->getCountry()->getIso()
                ],
                'phone' => [
                    'mobile'        => $this->getPhone($dataBag, $address),
                ],
                'email'         => $customer->getEmail()
            ]
        ];
    }

    /**
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
        $deliveries = $order->getDeliveries();

        if ($deliveries === null) {
            throw new \InvalidArgumentException('Deliveries cannot be null');
        }

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
        $address = $deliveries->getShippingAddress()->first();
        if ($address === null) {
            $address = $order->getBillingAddress();
        }

        if ($address === null) {
            throw new \InvalidArgumentException('Address cannot be null');
        }

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());

        if ($address->getCountry() === null) {
            throw new \InvalidArgumentException('Address country cannot be null');
        }

        return [
            'shipping' => [
                'recipient' => [
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             => $this->getBirthDate($dataBag),
                    'initials'              => strtoupper(substr($address->getFirstName(), 0, 1))
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
                    'country'               =>  $address->getCountry()->getIso()
                ],
            ],
        ];
    }



    /**
     *
     * @param OrderEntity $order
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    private function getArticles(OrderEntity $order, string $paymentCode): array
    {
        $lines = $this->formatRequestParamService->getOrderLinesArray($order, $paymentCode);

        $articles = [];

        foreach ($lines as $item) {
            $articles[] = [
                'identifier'        => $item['sku'],
                'description'       => $item['name'],
                'quantity'          => $item['quantity'],
                'price'             => $item['unitPrice']['value'],
                'vatCategory'       => $this->getItemVatCategory($item),
            ];
        }
        return [
            'articles' => $articles
        ];
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
     * Get birth date
     *
     * @param RequestDataBag $dataBag
     *
     * @return null|string
     */
    private function getBirthDate(RequestDataBag $dataBag)
    {
        if (!$dataBag->has('buckaroo_afterpay_DoB')) {
            return null;
        }

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

    /**
     * Get vat category
     *
     * @param array<mixed> $item
     *
     * @return int
     */
    private function getItemVatCategory(array $item): int
    {
        if (!isset($item['taxId']) ||
            !is_string($item['taxId'])
        ) {
            return 4;
        }

        $taxId = $item['taxId'];
        $taxAssociation = $this->settingsService->getSetting('afterpayOldtax');

        if (is_array($taxAssociation) && isset($taxAssociation[$taxId])) {
            return (int)$taxAssociation[$taxId];
        }

        return 4;
    }
}
