<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;


class FormatRequestParamService
{
    /**
     * Return an array of order lines.
     *
     * @param OrderEntity $order
     * @return array
     */
    public function getOrderLinesArray(OrderEntity $order)
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
            $vatAmount = $this->getPriceArray($currencyCode, $vatAmount);
            if ($unitPrice['value'] < 0) {
                $type               = 'Discount';
                $vatAmount['value'] = 0;
                $vatRate            = 0.0;
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
                'imageUrl'    => null,
                'productUrl'  => null,
            ];
        }

        $lines[] = $this->getShippingItemArray($order);

        if ($this->getBuckarooFeeArray($order)) {
            $lines[] = $this->getBuckarooFeeArray($order);
        }

        return $lines;
    }

    public function getProductLineData($order)
    {
        $lines = $this->getOrderLinesArray($order);

        $productData = [];
        $max = 99;
        $i = 1;

        foreach ($lines as $item) {
            $productData[] = $this->getRequestParameterRow($item['sku'], 'Code', 'ProductLine', $i);
            $productData[] = $this->getRequestParameterRow($item['name'], 'Name', 'ProductLine', $i);
            $productData[] = $this->getRequestParameterRow($item['quantity'], 'Quantity', 'ProductLine', $i);
            $productData[] = $this->getRequestParameterRow($item['unitPrice']['value'], 'Price', 'ProductLine', $i);

            $i++;

            if ($i > $max) {
                break;
            }
        }

        return $productData;
    }

    public function getProductLineDataCapture($order)
    {
        $lines = $this->getOrderLinesArray($order);

        $productData = [];
        $max = 99;
        $i = 1;

        foreach ($lines as $item) {
            $productData[] = $this->getRequestParameterRow($item['sku'], 'ArticleNumber', 'Article', $i);
            $productData[] = $this->getRequestParameterRow($item['quantity'], 'ArticleQuantity', 'Article', $i);

            $i++;

            if ($i > $max) {
                break;
            }
        }

        return $productData;
    }

    
    /**
     * @param string          $value
     * @param string          $name
     * @param null|string     $groupType
     * @param null|string|int $groupId
     *
     * @return array
     */
    public function getRequestParameterRow($value, $name, $groupType = null, $groupId = null)
    {
        $row = [
            '_' => $value,
            'Name' => $name
        ];

        if ($groupType !== null) {
            $row['Group'] = $groupType;
        }

        if ($groupId !== null) {
            $row['GroupID'] = $groupId;
        }

        return $row;
    }

    /**
     * Return a calculated tax struct for a line item.
     *
     * @param CalculatedTaxCollection $taxCollection
     * @return CalculatedTax|null
     */
    protected function getLineItemTax(CalculatedTaxCollection $taxCollection)
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
     * @return array
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
     * @return array
     */
    protected function getShippingItemArray(OrderEntity $order): array
    {
        // Variables
        $line     = [];
        $shipping = $order->getShippingCosts();

        if ($shipping === null) {
            return $line;
        }

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
            'vatAmount'   => $this->getPriceArray($currencyCode, $vatAmount),
            'sku'         => 'Shipping',
            'imageUrl'    => null,
            'productUrl'  => null,
        ];

        return $line;
    }

    protected function getBuckarooFeeArray(OrderEntity $order)
    {
        // Variables
        $line     = [];
        $customFields = $order->getCustomFields();

        if ($customFields === null || !isset($customFields['buckarooFee'])) {
            return false;
        }

        // Get currency code
        $currency     = $order->getCurrency();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';
        $buckarooFee = round((float)str_replace(',', '.', (string)$customFields['buckarooFee']), 2);

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
}
