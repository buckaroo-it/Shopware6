<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\In3;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class In3PaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = In3::class;


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

        return array_merge(
            [
                'invoiceDate'  => date('Y-m-d'),
                'customerType' => $dataBag->get('buckaroo_capayablein3_orderAs', 'Debtor'),
                'email'        =>  $order->getOrderCustomer()->getEmail(),
                'phone'        => [
                    'mobile' => $dataBag->get('buckaroo_in3_phone')
                ],
            ],
            $this->getCustomer($dataBag, $order),
            $this->getAddress($dataBag, $order),
            $this->getCompany($dataBag),
            $this->getArticles($order)
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
    ): string
    {
        return 'payInInstallments';
    }

    /**
     * Get customer info
     *
     * @param RequestDataBag $dataBag
     * @param OrderEntity $order
     *
     * @return array
     */
    private function getCustomer(RequestDataBag $dataBag, OrderEntity $order): array
    {

        $address = $order->getBillingAddress();
        $customer = $order->getOrderCustomer();

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
     * @param RequestDataBag $dataBag
     * @param OrderEntity $order
     *
     * @return array
     */
    private function getAddress(RequestDataBag $dataBag, OrderEntity $order): array
    {

        $address = $order->getBillingAddress();
        $streetData  = $this->formatRequestParamService->formatStreet($address->getStreet());

        $data = [
            'address' => [
                'street'      => $streetData['street'],
                'houseNumber' => $streetData['house_number'] ?? $address->getAdditionalAddressLine1(),
                'zipcode'     => $address->getZipCode(),
                'city'        => $address->getCity(),
                'country'     => $address->getCountry()->getIso()
            ]
        ];

        if (strlen($streetData['number_addition']) > 0) {
            $data['houseNumberAdditional'] = $streetData['number_addition'];
        }

        return $data;
    }

    /**
     * Get company data
     *
     * @param RequestDataBag $dataBag
     *
     * @return array
     */
    private function getCompany(RequestDataBag $dataBag): array
    {
        if (
            in_array(
                $dataBag->get('buckaroo_capayablein3_orderAs'),
                ['SoleProprietor', 'Company']
            )
        ) {
            return [
                'company' => [
                    'companyName'       => $dataBag->get('buckaroo_capayablein3_CompanyName'),
                    'chamberOfCommerce' => $dataBag->get('buckaroo_capayablein3_COCNumber')
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
     * @return array
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
    private function getInitials($name)
    {
        $initials = '';
        $nameParts = explode(' ', $name);

        if (empty($nameParts)) {
            return $initials;
        }

        foreach ($nameParts as $part) {
            $initials .= strtoupper(substr($part, 0, 1)) . '.';
        }

        return $initials;
    }
}
