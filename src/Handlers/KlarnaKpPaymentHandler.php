<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\KlarnaKp;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class KlarnaKpPaymentHandler extends AsyncPaymentHandler
{
    const KLARNAKP_ARTICLE_TYPE_GENERAL = 'General';
    const KLARNAKP_ARTICLE_TYPE_HANDLINGFEE = 'HandlingFee';
    const KLARNAKP_ARTICLE_TYPE_SHIPMENTFEE = 'ShipmentFee';

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
    ): RedirectResponse{

        $additional = [];
        $latestKey  = 1;
        $order      = $transaction->getOrder();

        $additional = $this->getArticleData($order, $additional, $latestKey);
        $additional = $this->getBuckarooFee($order, $additional, $latestKey);
        $additional = $this->getAddressArray($order, $additional, $latestKey, $salesChannelContext, $dataBag);

        $paymentMethod = new KlarnaKp();
        $gatewayInfo   = [
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

    protected function payBefore(
        RequestDataBag $dataBag,
        \Buckaroo\Shopware6\Buckaroo\Payload\Request $request
    ): void {
        $this->logger->info(__METHOD__ . "|1|");

        $request->setServiceAction('Reserve');

        parent::payBefore($dataBag, $request);
    }

    public function getBuckarooFee($order, $additional, &$latestKey)
    {
        $buckarooFee = $this->checkoutHelper->getBuckarooFee('klarnakpFee');
        if (false !== $buckarooFee && (double)$buckarooFee > 0) {
            $additional[] = [
                [
                    '_'       => 'BuckarooFee',
                    'Name'    => 'ArticleTitle',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => 'BuckarooFee',
                    'Name'    => 'ArticleNumber',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => 1,
                    'Name'    => 'ArticleQuantity',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => round($buckarooFee, 2),
                    'Name'    => 'ArticlePrice',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => 0,
                    'Name'    => 'ArticleVat',
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
                    'Name'    => 'ArticleTitle',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => $item['sku'],
                    'Name'    => 'ArticleNumber',
                    'Group'   => 'Article',
                    'GroupID' => $latestKey,
                ],
                [
                    '_'       => $item['quantity'],
                    'Name'    => 'ArticleQuantity',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => $item['unitPrice']['value'],
                    'Name'    => 'ArticlePrice',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => $item['vatRate'],
                    'Name'    => 'ArticleVat',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
                [
                    '_'       => $item['sku'] == 'Shipping' ? self::KLARNAKP_ARTICLE_TYPE_SHIPMENTFEE : self::KLARNAKP_ARTICLE_TYPE_GENERAL,
                    'Name'    => 'ArticleType',
                    'GroupID' => $latestKey,
                    'Group'   => 'Article',
                ],
            ];
            $latestKey++;
        }

        return $additional;
    }

    public function getAddressArray($order, $additional, &$latestKey, $salesChannelContext, $dataBag)
    {
        $this->logger->info(__METHOD__ . "|1|");

        $address  = $this->checkoutHelper->getBillingAddress($order, $salesChannelContext);
        $customer = $this->checkoutHelper->getOrderCustomer($order, $salesChannelContext);
        $shippingAddress  = $this->checkoutHelper->getShippingAddress($order, $salesChannelContext);

        if ($address === null) {
            return $additional;
        }

        $streetFormat  = $this->checkoutHelper->formatStreet($address->getStreet());
        $birthDayStamp = $dataBag->get('buckaroo_klarnakp_DoB');
        $address->setPhoneNumber($dataBag->get('buckaroo_klarnakp_phone'));
        $salutation = $customer->getSalutation()->getSalutationKey();

        $this->logger->info(__METHOD__ . "|5|", [$address->getId(), $shippingAddress->getId()]);
        
        $shippingAddress->setPhoneNumber($dataBag->get('buckaroo_klarnakp_phone'));
        $shippingStreetFormat  = $this->checkoutHelper->formatStreet($shippingAddress->getStreet());
        

        $category    = 'Person';
        $billingData = [
            [
                '_'       => $address->getFirstName(),
                'Name'    => 'BillingFirstName',
            ],
            [
                '_'       => $address->getLastName(),
                'Name'    => 'BillingLastName',
            ],
            [
                '_'       => (!empty($streetFormat['house_number']) ? $streetFormat['street'] : $address->getStreet()),
                'Name'    => 'BillingStreet',
            ],
            [
                '_'       => $address->getZipCode(),
                'Name'    => 'BillingPostalCode',
            ],
            [
                '_'       => $address->getCity(),
                'Name'    => 'BillingCity',
            ],
            [
                '_'       => $address->getCountry() !== null ? $address->getCountry()->getIso() : 'NL',
                'Name'    => 'BillingCountry',
            ],
            [
                '_'       => $address->getPhoneNumber(),
                'Name'    => 'BillingPhoneNumber',
            ],
            [
                '_'       => $customer->getEmail(),
                'Name'    => 'BillingEmail',
            ],
        ];

        if (!empty($streetFormat['house_number'])) {
            $billingData[] = [
                '_'       => $streetFormat['house_number'],
                'Name'    => 'BillingHouseNumber',
            ];
        }

        if (!empty($streetFormat['number_addition'])) {
            $billingData[] = [
                '_'       => $streetFormat['number_addition'],
                'Name'    => 'BillingHouseNumberSuffix',
            ];
        }

        $shippingData = [
            [
                '_'       => $shippingAddress->getFirstName(),
                'Name'    => 'ShippingFirstName',
            ],
            [
                '_'       => $shippingAddress->getLastName(),
                'Name'    => 'ShippingLastName',
            ],
            [
                '_'       => (!empty($shippingStreetFormat['house_number']) ? $shippingStreetFormat['street'] : $shippingAddress->getStreet()),
                'Name'    => 'ShippingStreet',
            ],
            [
                '_'       => $shippingAddress->getZipCode(),
                'Name'    => 'ShippingPostalCode',
            ],
            [
                '_'       => $shippingAddress->getCity(),
                'Name'    => 'ShippingCity',
            ],
            [
                '_'       => $shippingAddress->getCountry() !== null ? $shippingAddress->getCountry()->getIso() : 'NL',
                'Name'    => 'ShippingCountry',
            ],
            [
                '_'       => $shippingAddress->getPhoneNumber(),
                'Name'    => 'ShippingPhoneNumber',
            ],
            [
                '_'       => $customer->getEmail(),
                'Name'    => 'ShippingEmail',
            ],
        ];

        if (!empty($shippingStreetFormat['house_number'])) {
            $shippingData[] = [
                '_'       => $shippingStreetFormat['house_number'],
                'Name'    => 'ShippingHouseNumber',
            ];
        }

        if (!empty($shippingStreetFormat['number_addition'])) {
            $shippingData[] = [
                '_'       => $shippingStreetFormat['number_addition'],
                'Name'    => 'ShippingHouseNumberSuffix',
            ];
        }

        $latestKey++;

        $additional = array_merge($additional, [[
            [
                '_'       => $address->getCountry() !== null ? $address->getCountry()->getIso() : 'NL',
                'Name'    => 'OperatingCountry',
            ],
            [
                '_'       => $salutation == 'mrs' ? 2 : 1,
                'Name'    => 'Gender',
            ],
            [
                '_'       => $address->getId() == $shippingAddress->getId(),
                'Name'    => 'ShippingSameAsBilling',
            ]
        ]]);

        if ($birthDayStamp) {
            $additional = array_merge($additional, [[
                [
                    '_'       => gmdate('mdY', strtotime($birthDayStamp)),
                    'Name'    => 'Pno',
                ],
            ]]);
        }

        if ($address->getCompany()) {
            $additional = array_merge($additional, [[
                [
                    '_'       => $address->getCompany(),
                    'Name'    => 'BillingCompanyName',
                ],
                [
                    '_'       => $address->getCompany(),
                    'Name'    => 'ShippingCompany',
                ],
            ]]);
        }

        return array_merge($additional, [$billingData,$shippingData]);

    }
}
