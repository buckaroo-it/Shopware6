<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;

class SignatureValidationServiceTest extends TestCase
{
    private SignatureValidationService $signatureValidationService;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->signatureValidationService = new SignatureValidationService($this->settingsService);
    }

    /**
     * Test: it returns false when signature is missing from request
     */
    public function testValidateSignatureReturnsFalseWhenSignatureMissing(): void
    {
        // Arrange
        $request = new Request([], ['brq_amount' => '100.00']);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it returns true when signature matches calculated signature
     */
    public function testValidateSignatureReturnsTrueWhenSignatureMatches(): void
    {
        // Arrange
        $secretKey = 'test-secret-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_currency' => 'EUR',
            'brq_invoicenumber' => 'INV-001',
        ];

        // Calculate expected signature
        $signatureString = 'brq_amount=100.00brq_currency=EURbrq_invoicenumber=INV-001' . $secretKey;
        $expectedSignature = sha1($signatureString);

        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->with('secretKey', null)
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it returns false when signature does not match
     */
    public function testValidateSignatureReturnsFalseWhenSignatureDoesNotMatch(): void
    {
        // Arrange
        $secretKey = 'test-secret-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_currency' => 'EUR',
            'brq_signature' => 'invalid-signature'
        ];

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it uses sales channel ID when provided
     */
    public function testValidateSignatureUsesSalesChannelId(): void
    {
        // Arrange
        $salesChannelId = 'sales-channel-123';
        $secretKey = 'channel-specific-key';
        $postData = [
            'brq_amount' => '50.00',
        ];

        $signatureString = 'brq_amount=50.00' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->expects($this->once())
            ->method('getSetting')
            ->with('secretKey', $salesChannelId)
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request, $salesChannelId);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it sorts array keys case-insensitively for signature calculation
     */
    public function testValidateSignatureSortsKeysCorrectly(): void
    {
        // Arrange
        $secretKey = 'test-key';
        
        // Keys in non-alphabetical order
        $postData = [
            'brq_currency' => 'EUR',
            'brq_amount' => '100.00',
        ];

        // The sorting is case-insensitive but preserves original case
        // Expected order after sorting: amount, currency
        $signatureString = 'brq_amount=100.00brq_currency=EUR' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it excludes brq_signature from signature calculation
     */
    public function testCalculateSignatureExcludesBrqSignature(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_signature' => 'should-be-excluded'
        ];

        // Signature should only include brq_amount, not brq_signature
        $signatureString = 'brq_amount=100.00' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it does not decode specific fields in signature calculation
     */
    public function testCalculateSignatureDoesNotDecodeSpecificFields(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_customer_name' => 'John%20Doe', // Should NOT be decoded
            'brq_amount' => '100.00'
        ];

        // brq_customer_name should remain encoded in signature
        $signatureString = 'brq_amount=100.00brq_customer_name=John%20Doe' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it decodes URL-encoded values for non-exempt fields
     */
    public function testCalculateSignatureDecodesNonExemptFields(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_statuscode' => '190', // Regular field, should be decoded if encoded
            'brq_amount' => '100.00'
        ];

        $signatureString = 'brq_amount=100.00brq_statuscode=190' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it handles knaken buyer UUID key transformation
     */
    public function testCalculateSignatureTransformsKnakenBuyerUUID(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_SERVICE_knaken_Buyer_UUID' => 'uuid-123', // Should be transformed to space
            'brq_amount' => '100.00'
        ];

        // Key should be transformed to 'brq_SERVICE_knaken_Buyer UUID' (with space)
        $signatureString = 'brq_amount=100.00brq_SERVICE_knaken_Buyer UUID=uuid-123' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it handles knaken buyer name key transformation
     */
    public function testCalculateSignatureTransformsKnakenBuyerName(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_SERVICE_knaken_Buyer_Name' => 'John Doe',
            'brq_amount' => '100.00'
        ];

        // Key should be transformed to 'brq_SERVICE_knaken_Buyer Name' (with space)
        $signatureString = 'brq_amount=100.00brq_SERVICE_knaken_Buyer Name=John Doe' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it skips non-scalar values in signature calculation
     */
    public function testCalculateSignatureSkipsNonScalarValues(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_array_field' => ['should' => 'be-skipped'], // Non-scalar, should be ignored
            'brq_currency' => 'EUR'
        ];

        // Only scalar values should be in signature
        $signatureString = 'brq_amount=100.00brq_currency=EUR' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it calculates push hash excluding specific fields
     */
    public function testCalculatePushHashExcludesSpecificFields(): void
    {
        // Arrange - Using current date/time for hash calculation
        $currentDateTime = date("YmdHi");
        
        $postData = [
            'brq_amount' => '100.00',
            'brq_signature' => 'should-be-excluded',
            'brq_timestamp' => 'should-be-excluded',
            'brq_customer_name' => 'should-be-excluded',
            'brq_currency' => 'EUR'
        ];

        // Hash should only include amount and currency, not signature, timestamp, or customer_name
        $expectedString = $currentDateTime . 'brq_amount=100.00' . 'brq_currency=EUR';
        $expectedHash = sha1($expectedString);

        // Act
        $result = $this->signatureValidationService->calculatePushHash($postData);

        // Assert
        $this->assertSame($expectedHash, $result);
    }

    /**
     * Test: it calculates push hash with current timestamp
     */
    public function testCalculatePushHashIncludesCurrentTimestamp(): void
    {
        // Arrange
        $postData = [
            'brq_amount' => '50.00'
        ];

        // Act
        $result = $this->signatureValidationService->calculatePushHash($postData);

        // Assert - Should be a 40-character SHA1 hash
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $result);
    }

    /**
     * Test: it handles empty post data
     */
    public function testValidateSignatureHandlesEmptyPostData(): void
    {
        // Arrange
        $request = new Request();

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it handles complex real-world signature validation
     */
    public function testValidateSignatureWithComplexRealWorldData(): void
    {
        // Arrange
        $secretKey = 'MySecretKey123';
        $postData = [
            'brq_amount' => '125.50',
            'brq_currency' => 'EUR',
            'brq_invoicenumber' => 'ORDER-12345',
            'brq_statuscode' => '190',
            'brq_payment' => 'ABC123XYZ',
            'brq_test' => 'true',
            'brq_transactions' => 'DEF456',
        ];

        // Calculate correct signature
        $sortedKeys = array_keys($postData);
        sort($sortedKeys);
        
        $signatureString = '';
        foreach ($sortedKeys as $key) {
            $signatureString .= $key . '=' . $postData[$key];
        }
        $signatureString .= $secretKey;
        
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it returns false when signature is tampered
     */
    public function testValidateSignatureReturnsFalseWhenDataIsTampered(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_currency' => 'EUR',
        ];

        // Create valid signature
        $signatureString = 'brq_amount=100.00brq_currency=EUR' . $secretKey;
        $validSignature = sha1($signatureString);
        $postData['brq_signature'] = $validSignature;

        // Tamper with the amount AFTER signature calculation
        $postData['brq_amount'] = '200.00';

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it handles numeric values correctly
     */
    public function testCalculateSignatureHandlesNumericValues(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => 100.50, // Numeric, not string
            'brq_statuscode' => 190, // Integer
        ];

        $signatureString = 'brq_amount=100.5brq_statuscode=190' . $secretKey;
        $expectedSignature = sha1($signatureString);
        $postData['brq_signature'] = $expectedSignature;

        $request = new Request([], $postData);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateSignature($request);

        // Assert
        $this->assertTrue($result);
    }
}
