<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class In3V2
{
    protected AsyncPaymentService $asyncPaymentService;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        AsyncPaymentService $asyncPaymentService,
    ) {
        $this->asyncPaymentService = $asyncPaymentService;
    }

    /**
     * Get parameters for specific payment method
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     *
     * @return array<mixed>
     */
    public function getBody(
        OrderEntity $order,
        RequestDataBag $dataBag,
    ): array {
        return array_merge(
            [
                'invoiceDate'  => date('Y-m-d'),
                'customerType' => $dataBag->get('buckaroo_capayablein3_orderAs', 'Debtor'),
                'email'        =>  $this->asyncPaymentService->getCustomer($order)->getEmail(),
                'phone'        => [
                    'mobile' => $dataBag->get('buckaroo_in3_phone')
                ],
            ],
            $this->getCustomer($dataBag, $order),
            $this->getAddress($order),
            $this->getCompany($dataBag, $order),
            $this->getArticles($order)
        );
    }

    /**
     * Get customer info
     *
     * @param RequestDataBag $dataBag
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    private function getCustomer(RequestDataBag $dataBag, OrderEntity $order): array
    {

        $address = $this->asyncPaymentService->getBillingAddress($order);
        $customer = $this->asyncPaymentService->getCustomer($order);

        return [
            'customer' => [
                'initials'              => $this->getInitials($address->getFirstName()),
                'lastName'              => $address->getLastName(),
                'email'                 => $customer->getEmail(),
                'phone'                 => $dataBag->get('buckaroo_in3_phone'),
                'culture'               => 'nl-NL',
                'birthDate'             => $dataBag->get('buckaroo_capayablein3_DoB'),
            ]
        ];
    }

    /**
     * Get billing address data
     *
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    private function getAddress(OrderEntity $order): array
    {

        $address = $this->asyncPaymentService->getBillingAddress($order);
        $streetData  = $this->asyncPaymentService->formatRequestParamService->formatStreet($address->getStreet());

        $data = [
            'address' => [
                'street'      => $streetData['street'],
                'houseNumber' => $streetData['house_number'] ?? $address->getAdditionalAddressLine1(),
                'zipcode'     => $address->getZipCode(),
                'city'        => $address->getCity(),
                'country'     => $this->asyncPaymentService->getCountry($address)->getIso()
            ]
        ];

        if (isset($streetData['number_addition']) && strlen($streetData['number_addition']) > 0) {
            $data['address']['houseNumberAdditional'] = $streetData['number_addition'];
        } elseif ($address->getAdditionalAddressLine2() !== null && strlen($address->getAdditionalAddressLine2()) > 0) {
            $data['address']['houseNumberAdditional'] = $address->getAdditionalAddressLine2();
        }

        return $data;
    }

    /**
     * Get company data. Falls back to billing address vatId for the CoC
     * when the form input is empty.
     *
     * @param RequestDataBag $dataBag
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    private function getCompany(RequestDataBag $dataBag, OrderEntity $order): array
    {
        if (in_array(
            $dataBag->get('buckaroo_capayablein3_orderAs'),
            ['SoleProprietor', 'Company']
        )) {
            $billingAddress = $this->asyncPaymentService->getBillingAddress($order);

            $companyName = $dataBag->get('buckaroo_capayablein3_CompanyName');
            if (empty($companyName)) {
                $companyName = $billingAddress->getCompany() ?? '';
            }

            $coc = $dataBag->get('buckaroo_capayablein3_COCNumber');
            if (empty($coc)) {
                $coc = $billingAddress->getVatId() ?? '';
            }

            return [
                'company' => [
                    'companyName'       => $companyName,
                    'chamberOfCommerce' => $coc
                ]
            ];
        }

        return [];
    }

    /**
     * Get articles from order
     *
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    private function getArticles(OrderEntity $order): array
    {
        return [
            'articles' => $this->asyncPaymentService
                ->formatRequestParamService
                ->getProductLineData($order)
        ];
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function getInitials(string $name): string
    {
        $initials = '';

        if (strlen(trim($name)) === 0) {
            return '';
        }
        $nameParts = explode(' ', $name);
        foreach ($nameParts as $part) {
            $initials .= strtoupper(substr($part, 0, 1)) . '.';
        }

        return $initials;
    }
}
