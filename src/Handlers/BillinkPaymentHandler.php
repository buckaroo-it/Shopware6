<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Billink;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class BillinkPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = Billink::class;


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
        $billingAddress = $this->asyncPaymentService->getBillingAddress($order);
        return array_merge_recursive(
            $this->getVatNumber($order),
            $this->getCoc($billingAddress, $dataBag),
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
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        return 'pay';
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
        $address = $this->asyncPaymentService->getBillingAddress($order);
        $customer = $this->asyncPaymentService->getCustomer($order);

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
                    'careOf'                =>  $this->getCareOf($address),
                    'initials'              =>  $this->getInitials($address->getFirstName()),
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             =>  $this->getBirthDate($dataBag),
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
            ],
        ];
    }

    /**
     * Get vat number from the customer's registered VAT IDs.
     * Falls back to the billing address VAT ID when the customer's vatIds are empty
     * (e.g. the field was left blank during company registration).
     *
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    protected function getVatNumber(OrderEntity $order): array
    {
        $customer = $this->asyncPaymentService->getCustomer($order);
        $vatIds = $customer->getVatIds();
        if (!empty($vatIds) && is_array($vatIds)) {
            $first = reset($vatIds);
            if (is_string($first) && !empty(trim($first))) {
                return ['vATNumber' => trim($first)];
            }
        }

        $vatId = $this->asyncPaymentService->getBillingAddress($order)->getVatId();
        if (is_string($vatId) && !empty(trim($vatId))) {
            return ['vATNumber' => trim($vatId)];
        }

        return [];
    }

    /**
     * Get chamber of commerce (KvK) number — distinct from the VAT (BTW) number.
     * Priority:
     * 1. Checkout form input buckaroo_billink_coc (always shown for B2B, user may edit)
     * 2. Billing address vatId (Shopware's only built-in field for CoC in NL)
     *
     * @param OrderAddressEntity $billingAddress
     * @param RequestDataBag $dataBag
     *
     * @return array<mixed>
     */
    protected function getCoc(OrderAddressEntity $billingAddress, RequestDataBag $dataBag): array
    {
        $input = $dataBag->get('buckaroo_billink_coc');
        $coc = is_string($input) && !empty(trim($input)) ? trim($input) : '';

        if (empty($coc)) {
            $coc = $billingAddress->getVatId() ?? '';
        }

        if (!empty($coc)) {
            return [
                'billing' => [
                    'recipient' => [
                        'chamberOfCommerce' => $coc
                    ]
                ]
            ];
        }
        return [];
    }

    private function getPhone(RequestDataBag $dataBag, OrderAddressEntity $address): string
    {
        $phone = $dataBag->get('buckaroo_billink_phone', $address->getPhoneNumber());
        if (!is_scalar($phone)) {
            return '';
        }
        return (string)$phone;
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
                'price'             => $item['unitPrice']['value'],
                'vatPercentage'     => $item['vatRate'],
            ];
        }
        return [
            'articles' => $articles
        ];
    }

    /**
     * Get careOf
     *
     * @param OrderAddressEntity $address
     *
     * @return string
     */
    private function getCareOf(OrderAddressEntity $address): string
    {
        if ($address->getCompany() !== null &&
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
        if ($address->getCompany() !== null &&
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
     * @return null|string
     */
    private function getBirthDate(RequestDataBag $dataBag)
    {
        if (!$dataBag->has('buckaroo_billink_DoB')) {
            return null;
        }

        $dateString = $dataBag->get('buckaroo_billink_DoB');
        if (!is_scalar($dateString)) {
            return null;
        }
        $date = strtotime((string)$dateString);
        if ($date === false) {
            return null;
        }

        return @date("d-m-Y", $date);
    }
}
