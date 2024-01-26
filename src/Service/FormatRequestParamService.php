<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;

class FormatRequestParamService
{
    protected SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Return an array of order lines.
     *
     * @param OrderEntity $order
     * @return array<array>
     */
    public function getOrderLinesArray(OrderEntity $order, string $paymentCode = null)
    {
        // Variables
        $lines     = [];
        $lineItems = $order->getLineItems();

        if ($lineItems === null || $lineItems->count() === 0) {
            return $lines;
        }


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
            $vatRate = $itemTax !== null ? $itemTax->getTaxRate() : 0.0;
           
            if ($item->getUnitPrice() < 0) {
                $vatRate = 0.0;
            }

            $taxId = null;
            $itemPayload = $item->getPayload();
            if ($itemPayload !== null && !isset($itemPayload['taxId'])) {
                $taxId = $itemPayload['taxId'];
            }


            // Build the order lines array
            $lines[] = [
                'id'          => $item->getId(),
                'name'        => $item->getLabel(),
                'quantity'    => $item->getQuantity(),
                'unitPrice'   => $this->formatAmount($item->getUnitPrice()),
                'totalAmount' => $this->formatAmount($item->getTotalPrice()),
                'vatRate'     => $this->formatAmount($vatRate),
                'sku'         => $item->getId(),
                'taxId'       => $taxId
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

    


    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
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
                'price'             => $item['unitPrice']
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
     * Return an array of shipping data.
     *
     * @param OrderEntity $order
     * @return array<mixed>
     */
    protected function getShippingItemArray(OrderEntity $order): array
    {
        $shipping = $order->getShippingCosts();

        // Get shipping tax
        $shippingTax = null;

        if ($shipping->getCalculatedTaxes() !== null) {
            $shippingTax = $this->getLineItemTax($shipping->getCalculatedTaxes());
        }

        // Get VAT rate and amount
        $vatRate   = $shippingTax !== null ? $shippingTax->getTaxRate() : 0.0;

        // Build the order line array
        return [
            'id'          => 'shipping',
            'name'        => 'Shipping',
            'quantity'    => $shipping->getQuantity(),
            'unitPrice'   => $this->formatAmount($shipping->getUnitPrice()),
            'totalAmount' => $this->formatAmount($shipping->getTotalPrice()),
            'vatRate'     => $this->formatAmount($vatRate),
            'sku'         => 'Shipping',
        ];
    }


    private function getTaxAmount(OrderEntity $order): array
    {
        $tax = $order->getPrice()->getCalculatedTaxes()->getAmount();

        return [
            'id'          => 'vat',
            'name'        => 'VAT',
            'quantity'    => 1,
            'unitPrice'   => $this->formatAmount($tax),
            'totalAmount' => $this->formatAmount($tax),
            'vatRate'     => 0,
            'sku'         => 'vat',
        ];
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


        // Build the order line array
        return [
            'id'          => 'buckarooFee',
            'name'        => 'Buckaroo Fee',
            'quantity'    => 1,
            'unitPrice'   => $this->formatAmount($buckarooFee),
            'totalAmount' => $this->formatAmount($buckarooFee),
            'vatRate'     => 0,
            'sku'         => 'BuckarooFee',
        ];
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
            'house_number'    => '',
            'number_addition' => '',
            'street'          => $street,
        ];

        if (preg_match('#^(.*?)(\d+)(.*)#s', $street, $matches)) {
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
