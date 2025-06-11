<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;

class FormatRequestParamService
{
    protected SettingsService $settingsService;

    protected RiveryProductImageUrlService $riveryProductImageUrlService;

    public function __construct(
        SettingsService $settingsService,
        RiveryProductImageUrlService $riveryProductImageUrlService
    ) {
        $this->settingsService = $settingsService;
        $this->riveryProductImageUrlService = $riveryProductImageUrlService;
    }

    /**
     * Return an array of order lines.
     *
     * @param OrderEntity $order
     * @return array<array>
     */
    public function getOrderLinesArray(OrderEntity $order, string $paymentCode = null, ?Context $context = null)
    {
        // Variables
        $lines     = [];
        $lineItems = $order->getLineItems();

        if ($lineItems === null || $lineItems->count() === 0) {
            return $lines;
        }

        // Get currency code
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        foreach ($lineItems as $item) {
            // Get tax
            $itemTax = null;

            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $item */
            if (
                $item->getPrice() !== null &&
                $item->getPrice()->getCalculatedTaxes() !== null
            ) {
                $itemTax = $this->getLineItemTax($item->getPrice()->getCalculatedTaxes());
            }

            // Get VAT rate and amount
            $vatRate   = $itemTax !== null ? $itemTax->getTaxRate() : 0.0;
            $vatAmount = $itemTax !== null ? $itemTax->getTax() : null;

            if ($vatAmount === null && $vatRate > 0) {
                $vatAmount = $item->getTotalPrice() * ($vatRate / ($vatRate + 100));
            }

            $type      = 'Article';
            $unitPrice = $this->getPriceArray($currencyCode, $item->getUnitPrice());
            $vatAmount = $this->getPriceArray($currencyCode, (float)$vatAmount);
            if ($unitPrice['value'] < 0) {
                $type               = 'Discount';
                $vatAmount['value'] = 0;
                $vatRate            = 0.0;
            }

            $taxId = null;
            $itemPayload = $item->getPayload();
            if ($itemPayload !== null && isset($itemPayload['taxId'])) {
                $taxId = $itemPayload['taxId'];
            }

            // Build the order lines array
            $lines[] = [
                'id'          => $item->getId(),
                'type'        => $type,
                'name'        => $item->getLabel(),
                'quantity'    => $item->getQuantity(),
                'unitPrice'   => $unitPrice,
                'totalAmount' => $this->getPriceArray($currencyCode, $item->getTotalPrice()),
                'vatRate'     => number_format($vatRate, 2, '.', ''),
                'vatAmount'   => $vatAmount,
                'sku'         => $item->getId(),
                'imageUrl'    => $this->getProductImgUrl($item->getProductId(), $paymentCode, $context),
                'productUrl'  => null,
                'taxId'       => $taxId,
                'variations'  => $this->getVariations($item),
            ];
        }

        $lines[] = $this->getShippingItemArray($order);

        $fee = $this->getBuckarooFeeArray($order, $paymentCode);
        if (count($fee)) {
            $lines[] = $fee;
        }

        if ($order->getTaxStatus() === CartPrice::TAX_STATE_NET) {
            $lines[] = $this->getTaxAmount($order);
        }

        return $lines;
    }

    private function getProductImgUrl(
        ?string $productId,
        ?string $paymentCode,
        ?Context $context
    ): ?string {
        if ($productId === null || $paymentCode !== 'afterpay' || $context === null) {
            return null;
        }
        return $this->riveryProductImageUrlService->getImageUrl($productId, $context);
    }

    private function getVariations(OrderLineItemEntity $item): array
    {
        $payload = $item->getPayload();
        if (
            is_array($payload) &&
            isset($payload['options']) &&
            is_array($payload['options'])
        ) {
            return $payload['options'];
        }
        return [];
    }

    private function getTaxAmount(OrderEntity $order): array
    {
        // Get currency code
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        $tax = $order->getPrice()->getCalculatedTaxes()->getAmount();

        return [
            'id'          => 'vat',
            'type'        => 'vat',
            'name'        => 'VAT',
            'quantity'    => 1,
            'unitPrice'   => $this->getPriceArray($currencyCode, $tax),
            'totalAmount' => $this->getPriceArray($currencyCode, $tax),
            'vatRate'     => 0,
            'vatAmount'   => $this->getPriceArray($currencyCode, 0),
            'sku'         => 'vat',
            'imageUrl'    => null,
            'productUrl'  => null,
        ];
    }

    /**
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    public function getProductLineData(OrderEntity $order, callable $callback = null): array
    {
        $lines = $this->getOrderLinesArray($order);

        $productData = [];
        $max = 99;
        $i = 1;

        foreach ($lines as $item) {
            $product = [
                'identifier'        => $item['sku'],
                'description'       => $item['name'],
                'quantity'          => $item['quantity'],
                'price'             => $item['unitPrice']['value']
            ];

            if (is_callable($callback)) {
                $product = $callback($product, $item);
            }

            if (!is_array($product)) {
                continue;
            }
            $productData[] = $product;
            $i++;

            if ($i > $max) {
                break;
            }
        }

        return $productData;
    }


    /**
     * Return a calculated tax struct for a line item.
     *
     * @param CalculatedTaxCollection $taxCollection
     * @return CalculatedTax|null
     */
    protected function getLineItemTax(CalculatedTaxCollection $taxCollection): ?CalculatedTax
    {
        $tax = null;

        if ($taxCollection->count() > 0) {
            /** @var CalculatedTax $tax */
            $tax = $taxCollection->first();
        }

        return $tax;
    }

    /**
     * Return an array of price data; currency and value.
     * @param string $currency
     * @param float $price
     * @param int $decimals
     * @return array<mixed>
     */
    protected function getPriceArray(string $currency, float $price, int $decimals = 2): array
    {
        return [
            'currency' => $currency,
            'value'    => number_format($price, $decimals, '.', ''),
        ];
    }

    /**
     * Return an array of shipping data.
     *
     * @param OrderEntity $order
     * @return array<mixed>
     */
    protected function getShippingItemArray(OrderEntity $order): array
    {
        // Variables
        $line     = [];
        $shipping = $order->getShippingCosts();


        // Get currency code
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        // Get shipping tax
        $shippingTax = null;

        if ($shipping->getCalculatedTaxes() !== null) {
            $shippingTax = $this->getLineItemTax($shipping->getCalculatedTaxes());
        }

        // Get VAT rate and amount
        $vatRate   = $shippingTax !== null ? $shippingTax->getTaxRate() : 0.0;
        $vatAmount = $vatAmount = $shippingTax !== null ? $shippingTax->getTax() : null;

        if ($vatAmount === null && $vatRate > 0) {
            $vatAmount = $shipping->getTotalPrice() * ($vatRate / ($vatRate + 100));
        }

        // Build the order line array
        $line = [
            'id'          => 'shipping',
            'type'        => 'Shipping',
            'name'        => 'Shipping',
            'quantity'    => $shipping->getQuantity(),
            'unitPrice'   => $this->getPriceArray($currencyCode, $shipping->getUnitPrice()),
            'totalAmount' => $this->getPriceArray($currencyCode, $shipping->getTotalPrice()),
            'vatRate'     => number_format($vatRate, 2, '.', ''),
            'vatAmount'   => $this->getPriceArray($currencyCode, (float)$vatAmount),
            'sku'         => 'Shipping',
            'imageUrl'    => null,
            'productUrl'  => null,
        ];

        return $line;
    }

    /**
     * Get buckaroo fee from
     *
     * @param OrderEntity $order
     * @param string|null $paymentCode
     *
     * @return array<mixed>
     */
    protected function getBuckarooFeeArray(OrderEntity $order, string $paymentCode = null): array
    {
        $line = [];

        if ($paymentCode === null) {
            $buckarooFee = $order->getCustomFieldsValue('buckarooFee');
            if ($buckarooFee === null) {
                return $line;
            }
        } else {
            $buckarooFee = $this->settingsService->getBuckarooFee($paymentCode, $order->getSalesChannelId());
        }


        if (!is_float($buckarooFee) || $buckarooFee <= 0) {
            return $line;
        }

        // Get currency code
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';


        // Build the order line array
        $line = [
            'id'          => 'buckarooFee',
            'type'        => 'BuckarooFee',
            'name'        => 'Buckaroo Fee',
            'quantity'    => 1,
            'unitPrice'   => $this->getPriceArray($currencyCode, $buckarooFee),
            'totalAmount' => $this->getPriceArray($currencyCode, $buckarooFee),
            'vatRate'     => 0,
            'vatAmount'   => $this->getPriceArray($currencyCode, 0),
            'sku'         => 'BuckarooFee',
            'imageUrl'    => null,
            'productUrl'  => null,
        ];

        return $line;
    }

    /**
     * @param OrderAddressEntity $address
     * @param array<mixed> $parts
     *
     * @return string
     */
    public function getStreet(OrderAddressEntity $address, array $parts): string
    {
        if (!empty($parts['house_number'])) {
            return $parts['street'];
        }
        return (string)$address->getStreet();
    }

    /**
     * @param OrderAddressEntity $address
     * @param array<mixed> $parts
     *
     * @return string
     */
    public function getHouseNumber(OrderAddressEntity $address, array $parts): string
    {
        if (!empty($parts['house_number']) && is_scalar($parts['house_number'])) {
            return (string)$parts['house_number'];
        }
        return (string)$address->getAdditionalAddressLine1();
    }

    /**
     * @param OrderAddressEntity $address
     * @param array<mixed> $parts
     *
     * @return string
     */
    public function getAdditionalHouseNumber(OrderAddressEntity $address, array $parts): string
    {
        if (!empty($parts['number_addition']) && is_scalar($parts['number_addition'])) {
            return (string)$parts['number_addition'];
        }
        return (string)$address->getAdditionalAddressLine2();
    }
    /**
     * @param string $street
     *
     * @return array<mixed>
     */
    public function formatStreet(string $street): array
    {
        return self::getAddressParts($street);
    }

    /**
     * @param string $street
     *
     * @return array<mixed>
     */
    public static function getAddressParts(string $street): array
    {
        $format = [
            'house_number'    => null,
            'number_addition' => null,
            'street'          => $street,
        ];

        if (preg_match('#^(.*?)([0-9]+)(.*)#s', $street, $matches)) {
            // Check if the number is at the beginning of streetname
            if ('' == $matches[1]) {
                $format['house_number'] = trim($matches[2]);
                $format['street']       = trim($matches[3]);
            } else {
                $format['street']          = trim($matches[1]);
                $format['house_number']    = trim($matches[2]);
                $format['number_addition'] = trim(str_replace(',', '', $matches[3]));
            }
        }
        return $format;
    }
}
