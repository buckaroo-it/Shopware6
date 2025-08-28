<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Handlers\In3V2;
use Buckaroo\Shopware6\PaymentMethods\In3;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class In3PaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = In3::class;

    public const V2 = 'v2';

    /**
     * @var \Buckaroo\Shopware6\Handlers\In3V2
     */
    protected $in3v2;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        In3V2 $in3v2
    ) {
        parent::__construct($asyncPaymentService);
        $this->in3v2 = $in3v2;
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
    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {

        if ($this->isV2()) {
            return $this->in3v2->getBody($order, $dataBag);
        }

        return array_merge(
            $this->getBilling($dataBag, $order),
            $this->getShipping($dataBag, $order),
            $this->getArticles($order),
        );
    }


    /**
     * Check if is v2
     *
     * @return boolean
     */
    private function isV2(): bool
    {
        return $this->getSetting("capayableVersion") === self::V2;
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

        if ($this->isV2()) {
            return 'payInInstallments';
        }

        return parent::getMethodAction($dataBag, $salesChannelContext, $paymentCode);
    }

    private function getBilling(RequestDataBag $dataBag, OrderEntity $order): array
    {
        $address = $this->asyncPaymentService->getBillingAddress($order);
        $customer = $this->asyncPaymentService->getCustomer($order);
        $company = $this->getCompany($dataBag);

        $recipient = [
            'category'      => count($company) ? 'B2B' : 'B2C',
            'initials'      => $this->getInitials($address->getFirstName() . " " . $address->getLastName()),
            'firstName'     => $address->getFirstName(),
            'lastName'      => $address->getLastName(),
            'birthDate'     => $dataBag->get('buckaroo_capayablein3_DoB'),
            'customerNumber' => $customer->getCustomerNumber(),
            'phone'         => $dataBag->get('buckaroo_in3_phone'),
            'country'       => $this->asyncPaymentService->getCountry($address)->getIso()
        ];

        if (count($company)) {
            $recipient = array_merge($recipient, $company);
        }

        return [
            'billing' => [
                'recipient' => $recipient,
                'email' => $customer->getEmail(),
                'phone' => [
                    'phone' => $dataBag->get('buckaroo_in3_phone'),
                ],
                'address' => $this->getAddress($address)
            ]
        ];
    }

    /**
     * Get company data
     *
     * @param RequestDataBag $dataBag
     *
     * @return array<mixed>
     */
    private function getCompany(RequestDataBag $dataBag): array
    {
        if (in_array(
            $dataBag->get('buckaroo_capayablein3_orderAs'),
            ['SoleProprietor', 'Company']
        )) {
            return [
                'companyName'       => $dataBag->get('buckaroo_capayablein3_CompanyName'),
                'chamberOfCommerce' => $dataBag->get('buckaroo_capayablein3_COCNumber')
            ];
        }

        return [];
    }

    /**
     * Get customer info
     *
     * @param RequestDataBag $dataBag
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    private function getShipping(RequestDataBag $dataBag, OrderEntity $order): array
    {

        $address = $this->asyncPaymentService->getShippingAddress($order);
        $company = $this->getCompany($dataBag);

        $recipient = [
            'category'      => count($company) ? 'B2B' : 'B2C',
            'firstName'     => $address->getFirstName(),
            'lastName'      => $address->getLastName(),
            'careOf'        => $address->getFirstName() . ' ' . $address->getLastName()
        ];

        if (count($company)) {
            $recipient = array_merge(
                $recipient,
                $company
            );
        }

        return [
            'shipping' => [
                'recipient' => $recipient,
                'address' => $this->getAddress($address)
            ]
        ];
    }

    /**
     * Get billing address data
     *
     * @param OrderAddressEntity $address
     *
     * @return array<mixed>
     */
    private function getAddress(OrderAddressEntity $address): array
    {

        $streetData  = $this->formatRequestParamService->formatStreet($address->getStreet());

        $data = [
            'street'      => $streetData['street'],
            'houseNumber' => $streetData['house_number'] ?? $address->getAdditionalAddressLine1(),
            'zipcode'     => $address->getZipCode(),
            'city'        => $address->getCity(),
            'country'     => $this->asyncPaymentService->getCountry($address)->getIso()
        ];

        if (isset($streetData['number_addition']) && strlen($streetData['number_addition']) > 0) {
            $data['houseNumberAdditional'] = $streetData['number_addition'];
        } elseif (strlen($address->getAdditionalAddressLine2()) > 0) {
            $data['houseNumberAdditional'] = $address->getAdditionalAddressLine2();
        }

        return $data;
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
        $articles = $this->asyncPaymentService
            ->formatRequestParamService
            ->getProductLineData(
                $order,
                function ($product, $item) {
                    return array_merge(
                        $product,
                        ["vatPercentage" => is_scalar($item['vatRate']) ? (float)$item['vatRate'] : 0]
                    );
                }
            );


        return [
            'articles' => array_filter($articles, function ($article) {
                return is_array($article) &&
                    isset($article['price']) &&
                    is_scalar($article['price']) &&
                    (float)$article['price'] > 0;
            })
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
