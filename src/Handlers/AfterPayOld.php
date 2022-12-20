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
            $this->getArticles($order, $paymentCode, $salesChannelContext->getSalesChannelId())
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
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             => $this->getBirthDate($dataBag),
                    'initials'              => strtoupper(substr($address->getFirstName(), 0, 1)),
                    'culture'               => $address->getCountry()->getIso()
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
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             => $this->getBirthDate($dataBag),
                    'initials'              => strtoupper(substr($address->getFirstName(), 0, 1))
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

    

   
    private function getArticles(OrderEntity $order, string $paymentCode, string $salesChannelContextId): array
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
        return (string)$dataBag->get('buckaroo_afterpay_phone', $address->getPhoneNumber());
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

    /**
     * Get vat category
     *
     * @param array $item
     *
     * @return int
     */
    private function getItemVatCategory(array $item): int
    {
        if(
            !isset($item['taxId']) ||
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
