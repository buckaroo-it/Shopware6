<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Helpers\Helpers;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;

class AfterPayOld
{

    /**
     * @var \Buckaroo\Shopware6\Helpers\CheckoutHelper
     */
    protected $checkoutHelper;


    public function __construct(CheckoutHelper $checkoutHelper)
    {
        $this->checkoutHelper = $checkoutHelper;
    }

    public function buildPayParameters(
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        RequestDataBag $request
    ): array {

        $allArticles = array_merge(
            $this->getArticles($order),
            [$this->getShippingCost($order)],
        );

        $priceRoundingError = $this->getPriceRoundingErrors($order, $allArticles);
        if ($priceRoundingError > 0) {
            $allArticles[] = $this->getRoundingErrorArticle($priceRoundingError);
        }

        return array_merge($allArticles, [
            array_merge(
                $this->getFee($salesChannelContext),
                $this->getBilling($order, $salesChannelContext, $request),
                $this->getShipping($order, $salesChannelContext, $request)
            )
        ]);
    }

    /**
     * Get any rounding errors between the order total and the item total 
     * if the rounded error is larger then 0.01 we return it
     * 
     * @param OrderEntity $order
     * @param array $allArticles
     *
     * @return float
     */
    protected function getPriceRoundingErrors(OrderEntity $order, array $allArticles)
    {

        $orderTotal = $order->getAmountTotal();
        $articleTotal = 0;
        foreach ($allArticles as $article) {
            $articleTotal += $this->getArticleGroupValueByName($article, 'ArticleQuantity') * $this->getArticleGroupValueByName($article, 'ArticleUnitPrice');
        }
        $roundingError = $orderTotal - $articleTotal;
        if (abs(round($roundingError, 2)) > 0.01) {
            return $roundingError;
        }

        return 0;
    }

    private function getArticleGroupValueByName(array $articleGroup, string $name)
    {
        foreach ($articleGroup as $articleElement) {
            if ($articleElement['Name'] === $name) {
                return $articleElement['_'];
            }
        }
    }

    /**
     * Get rounding error article
     *
     * @param float $priceRoundingError
     *
     * @return array
     */
    protected function getRoundingErrorArticle(float $priceRoundingError)
    {

        $key = 'price_rounding';
        return [
            $this->setParameter('ArticleId', 'Price rounding', 'Article', $key),
            $this->setParameter('ArticleDescription', 'Price rounding', 'Article', $key),
            $this->setParameter('ArticleQuantity', 1, 'Article', $key),
            $this->setParameter('ArticleUnitPrice', round((float)$priceRoundingError, 2), 'Article', $key),
            $this->setParameter('ArticleVatCategory', 4, 'Article', $key)
        ];
    }
    /**
     * Get order articles
     *
     * @param OrderEntity $order
     *
     * @return array
     */
    protected function getArticles(
        OrderEntity $order
    ): array {

        $parameters = [];

        $lineItems = $order->getLineItems();

        if ($lineItems === null) {
            return [];
        }

        foreach ($lineItems as $key => $lineItem) {
            $parameters[] = [
                $this->setParameter('ArticleId', $lineItem->getId(), 'Article', $key),
                $this->setParameter('ArticleDescription', $lineItem->getLabel(), 'Article', $key),
                $this->setParameter('ArticleQuantity', $lineItem->getQuantity(), 'Article', $key),
                $this->setParameter('ArticleUnitPrice', round($lineItem->getUnitPrice(), 2), 'Article', $key),
                $this->setParameter('ArticleVatCategory', $this->getItemVatCategory($lineItem), 'Article', $key)
            ];
        }

        return $parameters;
    }


    /**
     * Get shipping cost as a request article
     *
     * @param OrderEntity $order
     *
     * @return array
     */
    protected function getShippingCost(
        OrderEntity $order
    ): array {

        $shippings = $order->getDeliveries()->map(
            function (OrderDeliveryEntity $orderDelivery) {
                return $orderDelivery->getShippingCosts()->getTotalPrice();
            }
        );

        $shipping = array_sum($shippings);
        $key = 'shipping';
        return [
            $this->setParameter('ArticleId', 'Shipping', 'Article', $key),
            $this->setParameter('ArticleDescription', 'Shipping', 'Article', $key),
            $this->setParameter('ArticleQuantity', 1, 'Article', $key),
            $this->setParameter('ArticleUnitPrice', round((float)$shipping, 2), 'Article', $key),
            $this->setParameter('ArticleVatCategory', 4, 'Article', $key)
        ];
    }

    /**
     * Get order fee as a request article
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    protected function getFee(
        SalesChannelContext $salesChannelContext
    ): array {
        $buckarooFee = $this->checkoutHelper->getBuckarooFee(
            'afterpayFee',
            $salesChannelContext->getSalesChannelId()
        );

        if ((float)$buckarooFee <= 0) {
            return [];
        }

        $key = 'buckarooFee';
        return [
            $this->setParameter('ArticleId', 'buckarooFee', 'Article', $key),
            $this->setParameter('ArticleDescription', 'buckarooFee', 'Article', $key),
            $this->setParameter('ArticleQuantity', 1, 'Article', $key),
            $this->setParameter('ArticleUnitPrice', round((float)$buckarooFee, 2), 'Article', $key),
            $this->setParameter('ArticleVatCategory', 4, 'Article', $key)
        ];
    }

    /**
     * Get customer billing data
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @param RequestDataBag $request
     *
     * @return array
     */
    protected function getBilling(
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        RequestDataBag $request
    ): array {


        /** @var \Shopware\Core\Checkout\Customer\CustomerEntity */
        $customer =  $this->checkoutHelper->getOrderCustomer($order, $salesChannelContext);

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
        $billingAddress = $order->getBillingAddress();

        if ($billingAddress === null) {
            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
            $billingAddress = $this->checkoutHelper->getBillingAddress($order, $salesChannelContext);
        }

        $country = $this->checkoutHelper->getCountryCode($billingAddress);
        $streetComponents = $this->checkoutHelper->formatStreet($billingAddress->getStreet());


        $parameters = [
            $this->setParameter('Accept', $request->has('buckaroo_afterpay_TermsCondition') ? 'true' : 'false'),
            $this->setParameter('BillingFirstName', $billingAddress->getFirstName()),
            $this->setParameter('BillingInitials', strtoupper(substr($billingAddress->getFirstName(), 0, 1))),
            $this->setParameter('BillingLastName', $billingAddress->getLastName()),
            $this->setParameter('BillingBirthDate', $request->get('buckaroo_afterpay_DoB', '01-01-1990')),
            $this->setParameter('BillingStreet', (!empty($streetComponents['house_number']) ? $streetComponents['street'] : $billingAddress->getStreet())),
            $this->setParameter('BillingHouseNumber', $streetComponents['house_number']),
            $this->setParameter('BillingPostalCode', $billingAddress->getZipcode()),
            $this->setParameter('BillingCity', $billingAddress->getCity()),
            $this->setParameter('BillingCountry', $country),
            $this->setParameter('BillingEmail', $customer->getEmail()),
            $this->setParameter('CustomerIPAddress', Helpers::getRemoteIp()),
            $this->setParameter(
                'BillingPhoneNumber',
                $request->get(
                    'buckaroo_afterpay_phone',
                    $billingAddress->getPhoneNumber()
                )
            ),
            $this->setParameter('BillingLanguage', $country),
        ];


        return $parameters;
    }

    /**
     * Get customer shipping data
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @param RequestDataBag $request
     *
     * @return array
     */
    protected function getShipping(
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        RequestDataBag $request
    ): array {
        /** @var \Shopware\Core\Checkout\Customer\CustomerEntity */
        $customer =  $this->checkoutHelper->getOrderCustomer($order, $salesChannelContext);

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
        $shippingAddress = $order->getDeliveries()->getShippingAddress()->first();

        if ($shippingAddress === null) {
            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
            $shippingAddress = $this->checkoutHelper->getShippingAddress($order, $salesChannelContext);
        }

        $country = $this->checkoutHelper->getCountryCode($shippingAddress);
        $streetComponents = $this->checkoutHelper->formatStreet($shippingAddress->getStreet());


        $parameters = [
            $this->setParameter('ShippingFirstName', $shippingAddress->getFirstName()),
            $this->setParameter('ShippingInitials', strtoupper(substr($shippingAddress->getFirstName(), 0, 1))),
            $this->setParameter('ShippingLastName', $shippingAddress->getLastName()),
            $this->setParameter('ShippingBirthDate', $request->get('buckaroo_afterpay_DoB', '01-01-1990')),
            $this->setParameter('ShippingStreet', (!empty($streetComponents['house_number']) ? $streetComponents['street'] : $shippingAddress->getStreet())),
            $this->setParameter('ShippingHouseNumber', $streetComponents['house_number']),
            $this->setParameter('ShippingPostalCode', $shippingAddress->getZipcode()),
            $this->setParameter('ShippingCity', $shippingAddress->getCity()),
            $this->setParameter('ShippingCountryCode', $country),
            $this->setParameter('ShippingEmail', $customer->getEmail()),
            $this->setParameter(
                'ShippingPhoneNumber',
                $request->get(
                    'buckaroo_afterpay_phone',
                    $shippingAddress->getPhoneNumber()
                )
            ),
            $this->setParameter('ShippingLanguage', $country),
        ];


        return $parameters;
    }

    /**
     * Get vat category
     *
     * @param OrderLineItemEntity $orderItem
     *
     * @return void
     */
    private function getItemVatCategory(OrderLineItemEntity $orderItem)
    {

        $itemPayload = $orderItem->getPayload();
        if ($itemPayload === null || !isset($itemPayload['taxId'])) {
            return 4;
        }

        $taxId = $itemPayload['taxId'];

        

        if(!is_string($taxId)) {
            return 4;
        }

        $taxAssociation = $this->checkoutHelper->getSettingsValue('afterpayOldtax');

        if (is_array($taxAssociation) && isset($taxAssociation[$taxId])) {
            return (int)$taxAssociation[$taxId];
        }

        return 4;
    }

    /**
     * Format data as a array of parameters
     *
     * @param string $name
     * @param mixed $value
     * @param string $groupType
     * @param string $groupId
     *
     * @return array
     */
    protected function setParameter(
        string $name,
        $value,
        string $groupType = '',
        string $groupId = ''
    ): array {
        return [
            "_" => $value,
            "Name" => $name,
            "GroupType" => $groupType,
            "GroupID" => $groupId
        ];
    }
}
