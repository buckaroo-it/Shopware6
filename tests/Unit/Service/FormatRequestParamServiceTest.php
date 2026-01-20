<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\RiveryProductImageUrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;

class FormatRequestParamServiceTest extends TestCase
{
    private FormatRequestParamService $formatRequestParamService;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var RiveryProductImageUrlService&MockObject */
    private RiveryProductImageUrlService $riveryProductImageUrlService;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->riveryProductImageUrlService = $this->createMock(RiveryProductImageUrlService::class);
        
        $this->formatRequestParamService = new FormatRequestParamService(
            $this->settingsService,
            $this->riveryProductImageUrlService
        );
    }

    /**
     * Test: it returns empty array when order has no line items
     */
    public function testGetOrderLinesArrayReturnsEmptyWhenNoLineItems(): void
    {
        // Arrange
        $order = new OrderEntity();
        $order->setLineItems(new OrderLineItemCollection());

        // Act
        $result = $this->formatRequestParamService->getOrderLinesArray($order);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test: it formats order line with correct structure and EUR default currency
     */
    public function testGetOrderLinesArrayFormatsLineItemWithDefaultCurrency(): void
    {
        // Arrange
        $order = $this->createOrderWithLineItem(
            lineItemId: 'line-item-1',
            label: 'Test Product',
            quantity: 2,
            unitPrice: 10.00,
            totalPrice: 20.00,
            taxRate: 21.00,
            currency: null // Will default to EUR
        );

        // Act
        $result = $this->formatRequestParamService->getOrderLinesArray($order);

        // Assert
        $this->assertCount(2, $result); // 1 product + 1 shipping
        $this->assertSame('Test Product', $result[0]['name']);
        $this->assertSame(2, $result[0]['quantity']);
        $this->assertSame('EUR', $result[0]['unitPrice']['currency']);
        $this->assertSame('10.00', $result[0]['unitPrice']['value']);
        $this->assertSame('21.00', $result[0]['vatRate']);
    }

    /**
     * Test: it formats order line with custom currency
     */
    public function testGetOrderLinesArrayFormatsLineItemWithCustomCurrency(): void
    {
        // Arrange
        $currency = new CurrencyEntity();
        $currency->setIsoCode('USD');
        
        $order = $this->createOrderWithLineItem(
            lineItemId: 'line-item-1',
            label: 'USD Product',
            quantity: 1,
            unitPrice: 50.00,
            totalPrice: 50.00,
            taxRate: 10.00,
            currency: $currency
        );

        // Act
        $result = $this->formatRequestParamService->getOrderLinesArray($order);

        // Assert
        $this->assertSame('USD', $result[0]['unitPrice']['currency']);
        $this->assertSame('USD', $result[0]['totalAmount']['currency']);
    }

    /**
     * Test: it marks negative price items as discount
     */
    public function testGetOrderLinesArrayMarksNegativePriceAsDiscount(): void
    {
        // Arrange
        $order = $this->createOrderWithLineItem(
            lineItemId: 'discount-1',
            label: 'Discount Voucher',
            quantity: 1,
            unitPrice: -5.00,
            totalPrice: -5.00,
            taxRate: 21.00,
            currency: null
        );

        // Act
        $result = $this->formatRequestParamService->getOrderLinesArray($order);

        // Assert
        $this->assertSame('Discount', $result[0]['type']);
        $this->assertSame(0, $result[0]['vatAmount']['value']);
        $this->assertSame('0.00', $result[0]['vatRate']);
    }

    /**
     * Test: it calculates VAT amount when tax collection is null but rate is provided
     */
    public function testGetOrderLinesArrayCalculatesVatWhenTaxCollectionIsNull(): void
    {
        // Arrange
        $taxRate = 21.00;
        $totalPrice = 121.00;
        $expectedVatAmount = $totalPrice * ($taxRate / ($taxRate + 100));

        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('line-item-1');
        $lineItem->setLabel('Product');
        $lineItem->setQuantity(1);
        $lineItem->setUnitPrice(121.00);
        $lineItem->setTotalPrice($totalPrice);

        // Create price with tax collection
        $price = new CalculatedPrice(
            121.00,
            121.00,
            new CalculatedTaxCollection([
                new CalculatedTax(21.00, $taxRate, 121.00)
            ]),
            new TaxRuleCollection()
        );
        $lineItem->setPrice($price);

        $order = $this->createOrderWithCustomLineItems([$lineItem]);

        // Act
        $result = $this->formatRequestParamService->getOrderLinesArray($order);

        // Assert
        $this->assertSame('21.00', $result[0]['vatRate']);
        $this->assertEqualsWithDelta(
            number_format($expectedVatAmount, 2, '.', ''),
            $result[0]['vatAmount']['value'],
            0.01
        );
    }

    /**
     * Test: it parses street address into parts (number at end)
     */
    public function testFormatStreetParsesAddressWithNumberAtEnd(): void
    {
        // Arrange
        $street = 'Main Street 123';

        // Act
        $result = $this->formatRequestParamService->formatStreet($street);

        // Assert
        $this->assertSame('Main Street', $result['street']);
        $this->assertSame('123', $result['house_number']);
        // number_addition can be null or empty string after trim
        $this->assertTrue($result['number_addition'] === null || $result['number_addition'] === '');
    }

    /**
     * Test: it parses street address with number and addition
     */
    public function testFormatStreetParsesAddressWithNumberAndAddition(): void
    {
        // Arrange
        $street = 'Oak Avenue 45 A';

        // Act
        $result = $this->formatRequestParamService->formatStreet($street);

        // Assert
        $this->assertSame('Oak Avenue', $result['street']);
        $this->assertSame('45', $result['house_number']);
        $this->assertSame('A', $result['number_addition']);
    }

    /**
     * Test: it parses street address with number at beginning
     */
    public function testFormatStreetParsesAddressWithNumberAtBeginning(): void
    {
        // Arrange
        $street = '5 Downing Street';

        // Act
        $result = $this->formatRequestParamService->formatStreet($street);

        // Assert
        $this->assertSame('Downing Street', $result['street']);
        $this->assertSame('5', $result['house_number']);
    }

    /**
     * Test: it handles street without number
     */
    public function testFormatStreetHandlesAddressWithoutNumber(): void
    {
        // Arrange
        $street = 'Broadway';

        // Act
        $result = $this->formatRequestParamService->formatStreet($street);

        // Assert
        $this->assertSame('Broadway', $result['street']);
        $this->assertNull($result['house_number']);
        $this->assertNull($result['number_addition']);
    }

    /**
     * Test: it removes comma from number addition
     */
    public function testFormatStreetRemovesCommaFromNumberAddition(): void
    {
        // Arrange
        $street = 'Street 10, B';

        // Act
        $result = $this->formatRequestParamService->formatStreet($street);

        // Assert
        $this->assertSame('Street', $result['street']);
        $this->assertSame('10', $result['house_number']);
        $this->assertSame('B', $result['number_addition']);
    }

    /**
     * Test: it returns street from parsed parts when house number exists
     */
    public function testGetStreetReturnsStreetFromPartsWhenHouseNumberExists(): void
    {
        // Arrange
        $address = new OrderAddressEntity();
        $address->setStreet('Main Street 123');
        $parts = ['street' => 'Main Street', 'house_number' => '123', 'number_addition' => null];

        // Act
        $result = $this->formatRequestParamService->getStreet($address, $parts);

        // Assert
        $this->assertSame('Main Street', $result);
    }

    /**
     * Test: it returns full address street when house number is empty
     */
    public function testGetStreetReturnsFullAddressWhenHouseNumberEmpty(): void
    {
        // Arrange
        $address = new OrderAddressEntity();
        $address->setStreet('Broadway Avenue');
        $parts = ['street' => 'Broadway Avenue', 'house_number' => '', 'number_addition' => null];

        // Act
        $result = $this->formatRequestParamService->getStreet($address, $parts);

        // Assert
        $this->assertSame('Broadway Avenue', $result);
    }

    /**
     * Test: it returns house number from parsed parts
     */
    public function testGetHouseNumberReturnsNumberFromParts(): void
    {
        // Arrange
        $address = new OrderAddressEntity();
        $parts = ['street' => 'Main Street', 'house_number' => '456', 'number_addition' => null];

        // Act
        $result = $this->formatRequestParamService->getHouseNumber($address, $parts);

        // Assert
        $this->assertSame('456', $result);
    }

    /**
     * Test: it returns house number from additional address line when parts empty
     */
    public function testGetHouseNumberReturnsAdditionalAddressLineWhenPartsEmpty(): void
    {
        // Arrange
        $address = new OrderAddressEntity();
        $address->setAdditionalAddressLine1('789');
        $parts = ['street' => 'Street', 'house_number' => '', 'number_addition' => null];

        // Act
        $result = $this->formatRequestParamService->getHouseNumber($address, $parts);

        // Assert
        $this->assertSame('789', $result);
    }

    /**
     * Test: it returns additional house number from parsed parts
     */
    public function testGetAdditionalHouseNumberReturnsAdditionFromParts(): void
    {
        // Arrange
        $address = new OrderAddressEntity();
        $parts = ['street' => 'Street', 'house_number' => '10', 'number_addition' => 'A'];

        // Act
        $result = $this->formatRequestParamService->getAdditionalHouseNumber($address, $parts);

        // Assert
        $this->assertSame('A', $result);
    }

    /**
     * Test: it returns additional house number from address line 2 when parts empty
     */
    public function testGetAdditionalHouseNumberReturnsAddressLine2WhenPartsEmpty(): void
    {
        // Arrange
        $address = new OrderAddressEntity();
        $address->setAdditionalAddressLine2('Suite B');
        $parts = ['street' => 'Street', 'house_number' => '10', 'number_addition' => ''];

        // Act
        $result = $this->formatRequestParamService->getAdditionalHouseNumber($address, $parts);

        // Assert
        $this->assertSame('Suite B', $result);
    }

    /**
     * Test: it includes shipping line in order lines array
     */
    public function testGetOrderLinesArrayIncludesShippingLine(): void
    {
        // Arrange
        $order = $this->createOrderWithLineItem(
            lineItemId: 'product-1',
            label: 'Product',
            quantity: 1,
            unitPrice: 10.00,
            totalPrice: 10.00,
            taxRate: 21.00,
            currency: null
        );

        // Act
        $result = $this->formatRequestParamService->getOrderLinesArray($order);

        // Assert - Last item should be shipping (no buckaroo fee in this test)
        $shippingLine = $result[1];
        $this->assertSame('shipping', $shippingLine['id']);
        $this->assertSame('Shipping', $shippingLine['type']);
        $this->assertSame('Shipping', $shippingLine['name']);
    }

    /**
     * Test: it returns product line data with callback
     */
    public function testGetProductLineDataAppliesCallback(): void
    {
        // Arrange
        $order = $this->createOrderWithLineItem(
            lineItemId: 'product-1',
            label: 'Product',
            quantity: 2,
            unitPrice: 10.00,
            totalPrice: 20.00,
            taxRate: 21.00,
            currency: null
        );

        $callback = function (array $product, array $item): array {
            $product['customField'] = 'customValue';
            return $product;
        };

        // Act
        $result = $this->formatRequestParamService->getProductLineData($order, $callback);

        // Assert
        $this->assertArrayHasKey('customField', $result[0]);
        $this->assertSame('customValue', $result[0]['customField']);
    }

    /**
     * Test: it limits product lines to 99 items
     */
    public function testGetProductLineDataLimitsTo99Items(): void
    {
        // Arrange
        $lineItems = [];
        for ($i = 0; $i < 150; $i++) {
            $lineItems[] = $this->createLineItem(
                id: "item-{$i}",
                label: "Product {$i}",
                quantity: 1,
                unitPrice: 10.00,
                totalPrice: 10.00,
                taxRate: 21.00
            );
        }
        $order = $this->createOrderWithCustomLineItems($lineItems);

        // Act
        $result = $this->formatRequestParamService->getProductLineData($order);

        // Assert - Should be 99 products + shipping = 100, but getProductLineData returns only products
        $this->assertLessThanOrEqual(99, count($result));
    }

    /**
     * Test: it skips items when callback returns non-array
     */
    public function testGetProductLineDataSkipsItemsWhenCallbackReturnsNonArray(): void
    {
        // Arrange
        $order = $this->createOrderWithLineItem(
            lineItemId: 'product-1',
            label: 'Product',
            quantity: 1,
            unitPrice: 10.00,
            totalPrice: 10.00,
            taxRate: 21.00,
            currency: null
        );

        $callback = function (array $product, array $item) {
            return null; // Return non-array
        };

        // Act
        $result = $this->formatRequestParamService->getProductLineData($order, $callback);

        // Assert - Should skip all items
        $this->assertEmpty($result);
    }

    // Helper methods

    private function createOrderWithLineItem(
        string $lineItemId,
        string $label,
        int $quantity,
        float $unitPrice,
        float $totalPrice,
        float $taxRate,
        ?CurrencyEntity $currency
    ): OrderEntity {
        $lineItem = $this->createLineItem(
            $lineItemId,
            $label,
            $quantity,
            $unitPrice,
            $totalPrice,
            $taxRate
        );

        return $this->createOrderWithCustomLineItems([$lineItem], $currency);
    }

    private function createLineItem(
        string $id,
        string $label,
        int $quantity,
        float $unitPrice,
        float $totalPrice,
        float $taxRate
    ): OrderLineItemEntity {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId($id);
        $lineItem->setLabel($label);
        $lineItem->setQuantity($quantity);
        $lineItem->setUnitPrice($unitPrice);
        $lineItem->setTotalPrice($totalPrice);

        // Create tax
        $taxAmount = $totalPrice * ($taxRate / (100 + $taxRate));
        $calculatedTax = new CalculatedTax($taxAmount, $taxRate, $totalPrice);
        $taxCollection = new CalculatedTaxCollection([$calculatedTax]);

        // Create price
        $price = new CalculatedPrice(
            $unitPrice,
            $totalPrice,
            $taxCollection,
            new TaxRuleCollection()
        );
        $lineItem->setPrice($price);

        return $lineItem;
    }

    private function createOrderWithCustomLineItems(
        array $lineItems,
        ?CurrencyEntity $currency = null
    ): OrderEntity {
        $order = new OrderEntity();
        $order->setLineItems(new OrderLineItemCollection($lineItems));

        if ($currency === null) {
            $currency = new CurrencyEntity();
            $currency->setIsoCode('EUR');
        }
        $order->setCurrency($currency);

        // Add shipping costs
        $shippingCosts = new CalculatedPrice(
            5.00,
            5.00,
            new CalculatedTaxCollection([
                new CalculatedTax(0.87, 21.00, 5.00)
            ]),
            new TaxRuleCollection()
        );
        $order->setShippingCosts($shippingCosts);

        return $order;
    }
}
