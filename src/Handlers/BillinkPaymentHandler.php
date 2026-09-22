<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Billink;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;

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

        $billing = [
            'recipient' => $this->filterEmpty([
                'category'              =>  $this->getCategory($address),
                'careOf'                =>  $this->getCareOf($address),
                'initials'              =>  $this->getInitials($address->getFirstName()),
                'firstName'             =>  $address->getFirstName(),
                'lastName'              =>  $address->getLastName(),
                'birthDate'             =>  $this->getBirthDate($dataBag, $customer),
                'salutation'            =>  $this->getGender($dataBag, $customer)
            ]),
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
            'email'         => $customer->getEmail()
        ];

        $phone = $this->getPhone($dataBag, $address, $customer);
        if ($phone !== null) {
            $billing['phone'] = [
                'mobile' => $phone,
            ];
        }

        return [
            'billing' => $billing
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
        $customer = $this->asyncPaymentService->getCustomer($order);

        $streetParts  = $this->formatRequestParamService->formatStreet($address->getStreet());
        return [
            'shipping' => [
                'recipient' => $this->filterEmpty([
                    'category'              =>  $this->getCategory($address),
                    'careOf'                =>  $this->getCareOf($address),
                    'initials'              =>  $this->getInitials($address->getFirstName()),
                    'firstName'             =>  $address->getFirstName(),
                    'lastName'              =>  $address->getLastName(),
                    'birthDate'             =>  $this->getBirthDate($dataBag, $customer),
                ]),
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

    /**
     * Get mobile phone number from existing Shopware data.
     * The checkout form no longer asks for it; Billink One requests it on the
     * hosted payment page when missing.
     * Priority:
     * 1. Legacy dataBag value (kept for backwards compatibility, e.g. headless clients)
     * 2. Billing address phone number
     * 3. Customer / address custom fields
     *
     * @param RequestDataBag $dataBag
     * @param OrderAddressEntity $address
     * @param OrderCustomerEntity $customer
     *
     * @return null|string
     */
    private function getPhone(
        RequestDataBag $dataBag,
        OrderAddressEntity $address,
        OrderCustomerEntity $customer
    ): ?string {
        $phone = $dataBag->get('buckaroo_billink_phone');
        if (is_scalar($phone) && !empty(trim((string)$phone))) {
            return trim((string)$phone);
        }

        $addressPhone = $address->getPhoneNumber();
        if (is_string($addressPhone) && !empty(trim($addressPhone))) {
            return trim($addressPhone);
        }

        return $this->getCustomFieldValue(
            [$address->getCustomFields(), $this->getCustomerCustomFields($customer)],
            ['buckaroo_billink_phone', 'phoneNumber', 'phone_number', 'phone', 'mobile', 'mobileNumber']
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

        $orderVatRate = $this->resolveOrderVatRate($order, $lines);

        $articles = [];

        foreach ($lines as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['sku'] ?? '') === 'BuckarooFee') {
                $articles[] = [
                    'identifier'    => 'ServiceCosts',
                    'description'   => 'Service Costs',
                    'quantity'      => 1,
                    'price'         => $item['unitPrice']['value'],
                    'vatPercentage' => number_format($orderVatRate, 2, '.', ''),
                ];
                continue;
            }

            $articles[] = [
                'identifier'    => $item['sku'],
                'description'   => $item['name'],
                'quantity'      => $item['quantity'],
                'price'         => $item['unitPrice']['value'],
                'vatPercentage' => $item['vatRate'],
            ];
        }
        return [
            'articles' => $articles
        ];
    }

    /**
     * Resolve the order's applicable VAT rate to use for the Buckaroo fee
     * Service Costs article, since the upstream BuckarooFee line's vatRate
     * is derived from the fee/order ratio rather than the order's tax rate.
     *
     * @param OrderEntity $order
     * @param array<mixed> $lines
     *
     * @return float
     */
    private function resolveOrderVatRate(OrderEntity $order, array $lines): float
    {
        $price = $order->getPrice();
        if ($price !== null) {
            $taxes = $price->getCalculatedTaxes();
            if ($taxes !== null && $taxes->count() > 0) {
                $dominant = null;
                foreach ($taxes as $tax) {
                    if ($dominant === null || $tax->getTax() > $dominant->getTax()) {
                        $dominant = $tax;
                    }
                }
                if ($dominant !== null && $dominant->getTaxRate() > 0) {
                    return (float)$dominant->getTaxRate();
                }
            }
        }

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $sku = $line['sku'] ?? '';
            if ($sku === 'BuckarooFee' || $sku === 'Shipping' || $sku === 'vat') {
                continue;
            }
            $rate = isset($line['vatRate']) ? (float)$line['vatRate'] : 0.0;
            if ($rate > 0) {
                return $rate;
            }
        }

        return 0.0;
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
     * Get birth date from existing Shopware data.
     * The checkout form no longer asks for it; Billink One requests it on the
     * hosted payment page when missing.
     * Priority:
     * 1. Legacy dataBag value (kept for backwards compatibility, e.g. headless clients)
     * 2. Customer profile birthday
     * 3. Customer custom fields
     *
     * @param RequestDataBag $dataBag
     * @param OrderCustomerEntity $customer
     *
     * @return null|string
     */
    private function getBirthDate(RequestDataBag $dataBag, OrderCustomerEntity $customer): ?string
    {
        $dateString = $dataBag->get('buckaroo_billink_DoB');
        if (is_scalar($dateString)) {
            $date = strtotime((string)$dateString);
            if ($date !== false) {
                return @date('d-m-Y', $date);
            }
        }

        $profile = $customer->getCustomer();
        if ($profile !== null && $profile->getBirthday() !== null) {
            return $profile->getBirthday()->format('d-m-Y');
        }

        $customValue = $this->getCustomFieldValue(
            [$this->getCustomerCustomFields($customer)],
            ['buckaroo_billink_DoB', 'buckaroo_dob', 'dateOfBirth', 'date_of_birth', 'birthday', 'dob']
        );
        if ($customValue !== null) {
            $date = strtotime($customValue);
            if ($date !== false) {
                return @date('d-m-Y', $date);
            }
        }

        return null;
    }

    /**
     * Get gender/salutation from existing Shopware data.
     * The checkout form no longer asks for it; Billink One requests it on the
     * hosted payment page when missing.
     * Priority:
     * 1. Legacy dataBag value (kept for backwards compatibility, e.g. headless clients)
     * 2. Derived from the order customer's salutation key
     *
     * @param RequestDataBag $dataBag
     * @param OrderCustomerEntity $customer
     *
     * @return null|string
     */
    private function getGender(RequestDataBag $dataBag, OrderCustomerEntity $customer): ?string
    {
        $gender = $dataBag->get('buckaroo_billink_gender');
        if (is_string($gender) && in_array($gender, ['Male', 'Female', 'Unknown'], true)) {
            return $gender;
        }

        $salutation = $customer->getSalutation();
        if ($salutation !== null) {
            if ($salutation->getSalutationKey() === 'mr') {
                return 'Male';
            }
            if ($salutation->getSalutationKey() === 'mrs') {
                return 'Female';
            }
        }

        return null;
    }

    /**
     * Get custom fields from the order customer and, when loaded,
     * the underlying customer profile.
     *
     * @param OrderCustomerEntity $customer
     *
     * @return array<mixed>
     */
    private function getCustomerCustomFields(OrderCustomerEntity $customer): array
    {
        $customFields = $customer->getCustomFields() ?? [];

        $profile = $customer->getCustomer();
        if ($profile !== null) {
            $customFields = array_merge($profile->getCustomFields() ?? [], $customFields);
        }

        return $customFields;
    }

    /**
     * Find the first non-empty string value for any of the candidate keys
     * in the given custom field sets.
     *
     * @param array<mixed> $customFieldSets
     * @param array<string> $keys
     *
     * @return null|string
     */
    private function getCustomFieldValue(array $customFieldSets, array $keys): ?string
    {
        foreach ($customFieldSets as $customFields) {
            if (!is_array($customFields)) {
                continue;
            }
            foreach ($keys as $key) {
                if (
                    isset($customFields[$key]) &&
                    is_scalar($customFields[$key]) &&
                    !empty(trim((string)$customFields[$key]))
                ) {
                    return trim((string)$customFields[$key]);
                }
            }
        }

        return null;
    }

    /**
     * Remove null and empty-string values so they are not sent to Billink.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function filterEmpty(array $data): array
    {
        return array_filter($data, function ($value) {
            return $value !== null && $value !== '';
        });
    }
}
