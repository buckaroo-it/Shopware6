<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\CartService;
use Buckaroo\Shopware6\Service\OrderService;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Buckaroo\Shopware6\Storefront\Exceptions\InvalidParameterException;

class AbstractPaymentControllerTest extends TestCase
{
    private TestableAbstractPaymentController $controller;
    private MockObject $cartService;
    private MockObject $customerService;
    private MockObject $orderService;
    private MockObject $settingsService;
    private MockObject $paymentMethodRepository;

    protected function setUp(): void
    {
        $this->cartService = $this->createMock(CartService::class);
        $this->customerService = $this->createMock(CustomerService::class);
        $this->orderService = $this->createMock(OrderService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->paymentMethodRepository = $this->createMock(SalesChannelRepository::class);

        $this->controller = new TestableAbstractPaymentController(
            $this->cartService,
            $this->customerService,
            $this->orderService,
            $this->settingsService,
            $this->paymentMethodRepository
        );
    }

    /**
     * Test getProductData with complete set of required keys
     */
    public function testGetProductDataWithCompleteKeys(): void
    {
        // Arrange
        $formData = new DataBag([
            'lineItems][id' => 'product-123',
            'lineItems][quantity' => '2',
            'lineItems][referencedId' => 'ref-456',
            'lineItems][removable' => '1',
            'lineItems][stackable' => '0',
            'lineItems][type' => 'product',
        ]);

        // Act
        $result = $this->controller->testGetProductData($formData);

        // Assert
        $this->assertEquals([
            'id' => 'product-123',
            'quantity' => 2,
            'referencedId' => 'ref-456',
            'removable' => true,
            'stackable' => false,
            'type' => 'product',
        ], $result);
    }

    /**
     * Test getProductData throws exception when required key 'id' is missing
     */
    public function testGetProductDataThrowsExceptionWhenIdMissing(): void
    {
        // Arrange
        $formData = new DataBag([
            // 'lineItems][id' => 'product-123', // Missing ID
            'lineItems][quantity' => '2',
            'lineItems][referencedId' => 'ref-456',
            'lineItems][removable' => '1',
            'lineItems][stackable' => '0',
            'lineItems][type' => 'product',
        ]);

        // Assert
        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('Missing required product parameters: id');

        // Act
        $this->controller->testGetProductData($formData);
    }

    /**
     * Test getProductData throws exception when multiple required keys are missing
     */
    public function testGetProductDataThrowsExceptionWhenMultipleKeysMissing(): void
    {
        // Arrange
        $formData = new DataBag([
            'lineItems][id' => 'product-123',
            // Missing quantity, referencedId, removable, stackable, type
        ]);

        // Assert
        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('Missing required product parameters:');

        // Act
        $this->controller->testGetProductData($formData);
    }

    /**
     * Test getProductData throws exception when all required keys are missing
     */
    public function testGetProductDataThrowsExceptionWhenAllKeysMissing(): void
    {
        // Arrange
        $formData = new DataBag([
            'otherField' => 'some-value', // No lineItems data
        ]);

        // Assert
        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage(
            'Missing required product parameters: '
            . 'id, quantity, referencedId, removable, stackable, type'
        );

        // Act
        $this->controller->testGetProductData($formData);
    }

    /**
     * Test getProductData with extra keys (should be ignored)
     */
    public function testGetProductDataWithExtraKeys(): void
    {
        // Arrange
        $formData = new DataBag([
            'lineItems][id' => 'product-123',
            'lineItems][quantity' => '3',
            'lineItems][referencedId' => 'ref-456',
            'lineItems][removable' => '1',
            'lineItems][stackable' => '1',
            'lineItems][type' => 'product',
            'lineItems][extraKey' => 'extra-value', // Extra key
            'otherField' => 'other-value', // Non-lineItems field
        ]);

        // Act
        $result = $this->controller->testGetProductData($formData);

        // Assert
        $this->assertEquals([
            'id' => 'product-123',
            'quantity' => 3,
            'referencedId' => 'ref-456',
            'removable' => true,
            'stackable' => true,
            'type' => 'product',
        ], $result);
    }

    /**
     * Test getProductData with type coercion for quantity
     */
    public function testGetProductDataWithQuantityTypeCoercion(): void
    {
        // Arrange
        $formData = new DataBag([
            'lineItems][id' => 'product-123',
            'lineItems][quantity' => '5.7', // String that can be converted to int
            'lineItems][referencedId' => 'ref-456',
            'lineItems][removable' => '1',
            'lineItems][stackable' => '0',
            'lineItems][type' => 'product',
        ]);

        // Act
        $result = $this->controller->testGetProductData($formData);

        // Assert
        $this->assertEquals(5, $result['quantity']); // Should be converted to int
    }

    /**
     * Test getProductData throws exception for invalid quantity type
     */
    public function testGetProductDataThrowsExceptionForInvalidQuantity(): void
    {
        // Arrange
        $formData = new DataBag([
            'lineItems][id' => 'product-123',
            'lineItems][quantity' => ['invalid'], // Array is not scalar
            'lineItems][referencedId' => 'ref-456',
            'lineItems][removable' => '1',
            'lineItems][stackable' => '0',
            'lineItems][type' => 'product',
        ]);

        // Assert
        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('Invalid quantity');

        // Act
        $this->controller->testGetProductData($formData);
    }

    /**
     * Test getProductData with boolean type coercion
     */
    public function testGetProductDataWithBooleanTypeCoercion(): void
    {
        // Arrange
        $formData = new DataBag([
            'lineItems][id' => 'product-123',
            'lineItems][quantity' => '1',
            'lineItems][referencedId' => 'ref-456',
            'lineItems][removable' => '0', // String '0' should become false
            'lineItems][stackable' => 'true', // String 'true' should become true
            'lineItems][type' => 'product',
        ]);

        // Act
        $result = $this->controller->testGetProductData($formData);

        // Assert
        $this->assertFalse($result['removable']);
        $this->assertTrue($result['stackable']);
    }

    /**
     * Test that the fix prevents the original loose comparison issue
     * This test ensures that array_diff is used instead of array_intersect with loose comparison
     */
    public function testStrictComparisonBehavior(): void
    {
        // This test verifies that the fix correctly identifies missing keys
        // Previously, loose comparison with array_intersect could have unexpected behavior
        
        // Test case 1: Subset of keys should fail
        $partialFormData = new DataBag([
            'lineItems][id' => 'product-123',
            'lineItems][quantity' => '1',
            // Missing other required keys
        ]);

        $this->expectException(InvalidParameterException::class);
        $this->controller->testGetProductData($partialFormData);
    }

    /**
     * Test edge case with empty form data
     */
    public function testGetProductDataWithEmptyFormData(): void
    {
        // Arrange
        $formData = new DataBag([]);

        // Assert
        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage(
            'Missing required product parameters: '
            . 'id, quantity, referencedId, removable, stackable, type'
        );

        // Act
        $this->controller->testGetProductData($formData);
    }

    /**
     * Test that required keys constant hasn't changed
     */
    public function testRequiredKeysAreComplete(): void
    {
        // This test ensures all required keys are checked
        $expectedKeys = ['id', 'quantity', 'referencedId', 'removable', 'stackable', 'type'];
        
        // Create form data missing just one key at a time to verify all are required
        foreach ($expectedKeys as $missingKey) {
            $formDataArray = [];
            foreach ($expectedKeys as $key) {
                if ($key !== $missingKey) {
                    $formDataArray["lineItems][$key"] = 'test-value';
                }
            }
            
            $formData = new DataBag($formDataArray);
            
            try {
                $this->controller->testGetProductData($formData);
                $this->fail("Expected InvalidParameterException when missing key: $missingKey");
            } catch (InvalidParameterException $e) {
                $this->assertStringContainsString($missingKey, $e->getMessage());
            }
        }
    }
}
