<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Klarna;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class KlarnaPaymentHandler extends AsyncPaymentHandler
{
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $buckarooKey
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $buckarooKey = null,
        string $type = null,
        string $version = null,
        array $gatewayInfo = []
    ): RedirectResponse {
        $dataBag = $this->getRequestBag($dataBag);

        $additional = [];
        $latestKey  = 1;
        $order      = $transaction->getOrder();

        $paymentMethod = new Klarna();
        $additional    = $this->getArticleTotalData($order, $additional, $latestKey, $paymentMethod->getBuckarooKey());
        // $additional = $this->getArticleData($order, $additional, $latestKey);
        // $additional = $this->getBuckarooFee($order, $additional, $latestKey);
        $additional = $this->getAddressArray($order, $additional, $latestKey, $salesChannelContext, $dataBag, $paymentMethod);

        $gatewayInfo = [
            'additional' => $additional,
        ];

        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentMethod->getBuckarooKey(),
            $paymentMethod->getType(),
            $paymentMethod->getVersion(),
            $gatewayInfo
        );
    }

    public function getBuckarooFee($order, $additional, &$latestKey)
    {
        $buckarooFee = $this->checkoutHelper->getBuckarooFee('klarnaFee');
        if (false !== $buckarooFee && (double) $buckarooFee > 0) {
            $additional[] = [
                [
                    '_'       => 'buckarooFee',
                    'Name'    => 'Description',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => 'buckarooFee',
                    'Name'    => 'Identifier',
                    'Group'   => 'Article',
                    'GroupID' => $latestKey,
                ],
                [
                    '_'       => 1,
                    'Name'    => 'Quantity',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => round($buckarooFee, 2),
                    'Name'    => 'GrossUnitPrice',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => 0,
                    'Name'    => 'VatPercentage',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
            ];
            $latestKey++;
        }
        return $additional;
    }

    public function getArticleData($order, $additional, &$latestKey)
    {
        $lines = $this->checkoutHelper->getOrderLinesArray($order);
        foreach ($lines as $key => $item) {
            $additional[] = [
                [
                    '_'       => $item['name'],
                    'Name'    => 'Description',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => $item['sku'],
                    'Name'    => 'Identifier',
                    'Group'   => 'Article',
                    'GroupID' => $latestKey,
                ],
                [
                    '_'       => $item['quantity'],
                    'Name'    => 'Quantity',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => $item['unitPrice']['value'],
                    'Name'    => 'GrossUnitPrice',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => $item['vatRate'],
                    'Name'    => 'VatPercentage',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
            ];
            $latestKey++;
        }

        return $additional;
    }

    public function getAddressArray($order, $additional, &$latestKey, $salesChannelContext, $dataBag, $paymentMethod)
    {
        $address         = $this->checkoutHelper->getBillingAddress($order, $salesChannelContext);
        $customer        = $this->checkoutHelper->getOrderCustomer($order, $salesChannelContext);
        $shippingAddress = $this->checkoutHelper->getShippingAddress($order, $salesChannelContext);

        if ($address === null) {
            return $additional;
        }

        $streetFormat  = $this->checkoutHelper->formatStreet($address->getStreet());
        $birthDayStamp = \DateTime::createFromFormat('Y-m-d', $dataBag->get('buckaroo_klarna_DoB'));
        $birthDayStamp = $birthDayStamp->format('d/m/Y');

        $address->setPhoneNumber($dataBag->get('buckaroo_klarna_phone'));
        $salutation = $this->checkoutHelper->getGenderFromSalutation($customer, 1);

        $shippingAddress->setPhoneNumber($dataBag->get('buckaroo_klarna_phone'));
        $shippingStreetFormat = $this->checkoutHelper->formatStreet($shippingAddress->getStreet());

        $category    = $this->helper->getSettingsValue($paymentMethod->getBuckarooKey() . 'Business') ?? 'B2C';
        $billingData = [
            [
                '_'       => $category,
                'Name'    => 'Category',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $address->getFirstName(),
                'Name'    => 'FirstName',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $address->getLastName(),
                'Name'    => 'LastName',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => (!empty($streetFormat['house_number']) ? $streetFormat['street'] : $address->getStreet()),
                'Name'    => 'Street',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $address->getZipCode(),
                'Name'    => 'PostalCode',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $address->getCity(),
                'Name'    => 'City',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $this->checkoutHelper->getCountryCode($address),
                'Name'    => 'Country',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $address->getPhoneNumber(),
                'Name'    => 'Phone',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $customer->getEmail(),
                'Name'    => 'Email',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ],
        ];

        if (!empty($streetFormat['house_number'])) {
            $billingData[] = [
                '_'       => $streetFormat['house_number'],
                'Name'    => 'StreetNumber',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ];
        }elseif(!empty($address->getAdditionalAddressLine1())){
            $billingData[] = [
                '_'       => $address->getAdditionalAddressLine1(),
                'Name'    => 'StreetNumber',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ]; 
        }

        if (!empty($streetFormat['number_addition'])) {
            $billingData[] = [
                '_'       => $streetFormat['number_addition'],
                'Name'    => 'StreetNumberAdditional',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ];
        }elseif(!empty($address->getAdditionalAddressLine2())){
            $billingData[] = [
                '_'       => $address->getAdditionalAddressLine2(),
                'Name'    => 'StreetNumberAdditional',
                'Group'   => 'BillingCustomer',
                'GroupID' => '',
            ]; 
        }

        $billingData[] = [
            '_'       => $salutation,
            'Name'    => 'Gender',
            'Group'   => 'BillingCustomer',
            'GroupID' => '',
        ];

        $billingData[] = [
            '_'       => $birthDayStamp,
            'Name'    => 'BirthDate',
            'Group'   => 'BillingCustomer',
            'GroupID' => '',
        ];

        $shippingData = [
            [
                '_'       => $category,
                'Name'    => 'Category',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $shippingAddress->getFirstName(),
                'Name'    => 'FirstName',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $shippingAddress->getLastName(),
                'Name'    => 'LastName',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => (!empty($shippingStreetFormat['house_number']) ? $shippingStreetFormat['street'] : $shippingAddress->getStreet()),
                'Name'    => 'Street',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $shippingAddress->getZipCode(),
                'Name'    => 'PostalCode',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $shippingAddress->getCity(),
                'Name'    => 'City',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $this->checkoutHelper->getCountryCode($shippingAddress),
                'Name'    => 'Country',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $shippingAddress->getPhoneNumber(),
                'Name'    => 'Phone',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'       => $customer->getEmail(),
                'Name'    => 'Email',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ],
        ];

        if (!empty($shippingStreetFormat['house_number'])) {
            $shippingData[] = [
                '_'       => $shippingStreetFormat['house_number'],
                'Name'    => 'StreetNumber',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ];
        }elseif (!empty($shippingAddress->getAdditionalAddressLine1())) {
            $shippingData[] = [
                '_'       => $shippingAddress->getAdditionalAddressLine1(),
                'Name'    => 'StreetNumber',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ];
        }

        if (!empty($shippingStreetFormat['number_addition'])) {
            $shippingData[] = [
                '_'       => $shippingStreetFormat['number_addition'],
                'Name'    => 'StreetNumberAdditional',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ];
        }elseif(!empty($shippingAddress->getAdditionalAddressLine2())){
            $shippingData[] = [
                '_'       => $shippingAddress->getAdditionalAddressLine2(),
                'Name'    => 'StreetNumberAdditional',
                'Group'   => 'ShippingCustomer',
                'GroupID' => '',
            ];
        }

        $shippingData[] = [
            '_'       => $salutation,
            'Name'    => 'Gender',
            'Group'   => 'ShippingCustomer',
            'GroupID' => '',
        ];

        $shippingData[] = [
            '_'       => $birthDayStamp,
            'Name'    => 'BirthDate',
            'Group'   => 'ShippingCustomer',
            'GroupID' => '',
        ];

        $latestKey++;

        return array_merge($additional, [$billingData, $shippingData]);

    }

    public function getArticleTotalData($order, $additional, &$latestKey, $buckarooKey)
    {
        $total   = $order->getAmountTotal();
        if ($buckarooFee = $this->checkoutHelper->getBuckarooFee($buckarooKey . 'Fee')) {
            $total += $buckarooFee;
        }

        $additional[] = [
            [
                '_'       => 'Total product',
                'Name'    => 'Description',
                'GroupID' => $latestKey,
                'Group'   => 'Article',
            ],
            [
                '_'       => 0,
                'Name'    => 'Identifier',
                'Group'   => 'Article',
                'GroupID' => $latestKey,
            ],
            [
                '_'       => 1,
                'Name'    => 'Quantity',
                'GroupID' => $latestKey,
                'Group'   => 'Article',
            ],
            [
                '_'       => $total,
                'Name'    => 'GrossUnitPrice',
                'GroupID' => $latestKey,
                'Group'   => 'Article',
            ],
            [
                '_'       => 10,
                'Name'    => 'VatPercentage',
                'GroupID' => $latestKey,
                'Group'   => 'Article',
            ],
        ];
        $latestKey++;

        return $additional;
    }
}
