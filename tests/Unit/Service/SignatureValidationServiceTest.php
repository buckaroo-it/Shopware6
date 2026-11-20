<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Buckaroo\Shopware6\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\EventListener\JsonRequestTransformerListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SignatureValidationServiceTest extends TestCase
{
    private SignatureValidationService $signatureValidationService;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->signatureValidationService = new SignatureValidationService($this->settingsService, $this->logger);
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
     * A push is rejected when no secret key is configured.
     *
     * @dataProvider missingSecretKeyProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingSecretKeyProvider')]
    public function testValidateSignatureRejectsPushWhenSecretKeyIsMissing(mixed $secretKey): void
    {
        $postData = ['brq_amount' => '50.00'];
        $postData['brq_signature'] = sha1('brq_amount=50.00');

        $this->settingsService
            ->method('getSetting')
            ->with('secretKey', 'sales-channel-without-secret')
            ->willReturn($secretKey);

        $this->assertFalse(
            $this->signatureValidationService->validateSignature(
                new Request([], $postData),
                'sales-channel-without-secret'
            )
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function missingSecretKeyProvider(): array
    {
        return [
            'not configured' => [null],
            'empty' => [''],
            'whitespace only' => ['   '],
        ];
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
     * Test: it skips non-scalar values in signature calculation
     */
    public function testValidateSignatureRejectsNonScalarValues(): void
    {
        // Arrange: a signed field that is not scalar is not part of Buckaroo's push
        // format. The SDK does not silently skip it; validation fails closed and the
        // push is rejected rather than authenticated on a partial payload.
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_array_field' => ['unexpected' => 'array'],
            'brq_currency' => 'EUR',
            'brq_signature' => sha1('brq_amount=100.00brq_currency=EUR' . $secretKey),
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
     * Test: the signed push data carries the values the signature was validated over
     */
    public function testGetSignedPushDataReturnsTheValuesAsSigned(): void
    {
        $request = new Request([], [
            'brq_transactions' => '%6Bey-123',
            'brq_mutationtype' => 'Processing',
            'brq_customer_name' => 'J%20Doe',
            'brq_SERVICE_boekenbon_Additional_Info' => 'info',
            'brq_list' => ['not', 'a', 'string'],
        ]);

        $this->assertSame(
            [
                'brq_transactions' => 'key-123',
                'brq_mutationtype' => 'Processing',
                'brq_customer_name' => 'J%20Doe',
                'brq_SERVICE_boekenbon_Additional Info' => 'info',
                'brq_list' => ['not', 'a', 'string'],
            ],
            $this->signatureValidationService->getSignedPushData($request)
        );
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
     * Test: signed values must be strings; numeric values are not accepted, even when
     * the signature matches their string form.
     */
    public function testValidateSignatureRejectsNumericSignedValues(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => 100.50,
            'brq_statuscode' => 190,
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
        $this->assertFalse($result);
    }

    /**
     * Test: a push that duplicates the signature field under a different letter casing
     * (e.g. brq_signature + BRQ_SIGNATURE) is rejected. Field names are case-insensitive
     * to consumers, so such an ambiguous payload must never authenticate.
     */
    public function testValidateSignatureRejectsCaseCollidingSignatureField(): void
    {
        // Arrange
        $secretKey = 'test-secret-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_signature' => sha1('brq_amount=100.00' . $secretKey),
            'BRQ_SIGNATURE' => 'attacker-controlled',
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
     * Test: Buckaroo signs the boekenbon field with a space in its name, which PHP turns
     * into an underscore when parsing the request. The original name is restored before hashing.
     */
    public function testValidateSignatureRestoresBoekenbonAdditionalInfoKey(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_SERVICE_boekenbon_Additional_Info' => 'info',
            'brq_signature' => sha1(
                'brq_amount=100.00brq_SERVICE_boekenbon_Additional Info=info' . $secretKey
            ),
        ];

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
     * Test: values that are not exempt are url-decoded before hashing, so encoded
     * characters such as %XX and + match the value Buckaroo signed.
     */
    public function testValidateSignatureUrlDecodesNonExemptValues(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_statusmessage' => 'Transaction+successfully+processed%3A+100%25',
            'brq_signature' => sha1(
                'brq_amount=100.00brq_statusmessage=Transaction successfully processed: 100%' . $secretKey
            ),
        ];

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
     * Test: a signature over the still-encoded value of a non-exempt field is rejected,
     * as Buckaroo signs the decoded value.
     */
    public function testValidateSignatureRejectsSignatureOverEncodedNonExemptValue(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $postData = [
            'brq_amount' => '100.00',
            'brq_statusmessage' => 'a+b%2Bc',
            'brq_signature' => sha1('brq_amount=100.00brq_statusmessage=a+b%2Bc' . $secretKey),
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
     * Test: the same key and value normalisation applies to the return signature.
     */
    public function testValidateReturnSignatureNormalizesKeysAndValues(): void
    {
        // Arrange
        $secretKey = 'test-key';
        $query = [
            'brq_SERVICE_boekenbon_Additional_Info' => 'info',
            'brq_statusmessage' => 'a+b%2Bc',
            'brq_signature' => sha1(
                'brq_SERVICE_boekenbon_Additional Info=infobrq_statusmessage=a b+c' . $secretKey
            ),
        ];

        $request = new Request($query);

        $this->settingsService
            ->method('getSetting')
            ->willReturn($secretKey);

        // Act
        $result = $this->signatureValidationService->validateReturnSignature($request);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: the signed return data comes from the same source as the return signature
     * check - the body when it carries the signature, otherwise the query string - with
     * the values as Buckaroo signed them.
     */
    public function testGetSignedReturnDataReadsTheSourceOfTheSignature(): void
    {
        $fromQuery = $this->signatureValidationService->getSignedReturnData(
            new Request(['brq_statusmessage' => 'a+b%2Bc', 'brq_signature' => 'query-signature'])
        );
        $fromBody = $this->signatureValidationService->getSignedReturnData(
            new Request(
                ['brq_statuscode' => '890', 'brq_signature' => 'query-signature'],
                ['brq_statuscode' => '190', 'brq_signature' => 'body-signature']
            )
        );

        $this->assertSame(
            ['brq_statusmessage' => 'a b+c', 'brq_signature' => 'query-signature'],
            $fromQuery
        );
        $this->assertSame(
            ['brq_statuscode' => '190', 'brq_signature' => 'body-signature'],
            $fromBody
        );
    }

    /**
     * Test: a push with a JSON body whose signed field is null is rejected, without
     * raising a PHP warning or deprecation.
     */
    public function testPushWithJsonNullSignedValueIsRejectedWithoutPhpNotice(): void
    {
        $request = $this->createJsonRequest($this->createSignedBody(['brq_statusmessage' => null]));

        $this->expectNoSignatureCheck();

        $this->assertFalse($this->callWithoutPhpNotices(
            fn () => $this->signatureValidationService->validateSignature($request)
        ));
    }

    /**
     * Test: a return with a JSON body whose signed field is null is rejected, without
     * raising a PHP warning or deprecation.
     */
    public function testReturnWithJsonNullSignedValueIsRejectedWithoutPhpNotice(): void
    {
        $request = $this->createJsonRequest($this->createSignedBody(['brq_statusmessage' => null]));

        $this->expectNoSignatureCheck();

        $this->assertFalse($this->callWithoutPhpNotices(
            fn () => $this->signatureValidationService->validateReturnSignature($request)
        ));
    }

    /**
     * Test: every signed prefix only accepts string values, on the push and the return path.
     *
     * @dataProvider nonStringSignedValueProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonStringSignedValueProvider')]
    public function testNonStringSignedValueIsRejected(string $field, mixed $value): void
    {
        $request = new Request([], $this->createSignedBody([$field => $value]));

        $this->expectNoSignatureCheck();

        $this->callWithoutPhpNotices(function () use ($request): void {
            $this->assertFalse($this->signatureValidationService->validateSignature($request));
            $this->assertFalse($this->signatureValidationService->validateReturnSignature($request));
        });
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function nonStringSignedValueProvider(): array
    {
        $cases = [];
        $values = [
            'null' => null,
            'true' => true,
            'false' => false,
            'integer' => 190,
            'float' => 10.5,
            'array' => ['a', 'b'],
            'object' => new \stdClass(),
        ];

        foreach (['brq_statusmessage', 'BRQ_StatusMessage', 'add_orderid', 'cust_note', 'wc_order'] as $field) {
            foreach ($values as $type => $value) {
                $cases[$field . ' ' . $type] = [$field, $value];
            }
        }

        return $cases;
    }

    /**
     * Test: JSON values of every non-string type are rejected on both paths.
     *
     * @dataProvider jsonNonStringValueProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jsonNonStringValueProvider')]
    public function testJsonNonStringSignedValueIsRejected(mixed $value): void
    {
        $request = $this->createJsonRequest($this->createSignedBody(['brq_statusmessage' => $value]));

        $this->expectNoSignatureCheck();

        $this->callWithoutPhpNotices(function () use ($request): void {
            $this->assertFalse($this->signatureValidationService->validateSignature($request));
            $this->assertFalse($this->signatureValidationService->validateReturnSignature($request));
        });
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function jsonNonStringValueProvider(): array
    {
        return [
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'integer' => [190],
            'float' => [10.5],
            'list' => [['a', 'b']],
            'object' => [['nested' => 'value']],
        ];
    }

    /**
     * Test: a correctly signed JSON body with string values still validates on both paths,
     * including empty strings, unsigned non-string fields and HTML entity decoding.
     */
    public function testJsonStringSignedValuesAreAccepted(): void
    {
        $secretKey = 'test-key';
        $body = [
            'brq_amount' => '100.00',
            'add_note' => '',
            'brq_description' => 'Fish &amp; Chips',
            'wc_order' => '1001',
            'orderId' => null,
        ];
        $body['brq_signature'] = sha1(
            'add_note=brq_amount=100.00brq_description=Fish & Chips' . $secretKey
        );
        $request = $this->createJsonRequest($body);

        $this->settingsService->method('getSetting')->willReturn($secretKey);
        $this->logger->expects($this->never())->method('error');

        $this->callWithoutPhpNotices(function () use ($request): void {
            $this->assertTrue($this->signatureValidationService->validateSignature($request));
            $this->assertTrue($this->signatureValidationService->validateReturnSignature($request));
        });
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function createSignedBody(array $fields): array
    {
        return ['brq_amount' => '100.00'] + $fields + ['brq_signature' => sha1('brq_amount=100.00test-key')];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createJsonRequest(array $body): Request
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body, \JSON_THROW_ON_ERROR)
        );

        (new JsonRequestTransformerListener())->onRequest(
            new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST)
        );

        return $request;
    }

    /**
     * The request is rejected before the secret key is read and before the SDK runs.
     */
    private function expectNoSignatureCheck(): void
    {
        $this->settingsService->expects($this->never())->method('getSetting');
        $this->logger->expects($this->never())->method('error');
    }

    /**
     * Run the callback with an error handler that records every PHP warning, notice and
     * deprecation, and fail when any was raised. Errors are recorded rather than only thrown,
     * as the service catches exceptions raised during validation.
     */
    private function callWithoutPhpNotices(callable $callback): mixed
    {
        $errors = [];
        set_error_handler(
            static function (int $errno, string $errstr, string $errfile, int $errline) use (&$errors): bool {
                $errors[] = sprintf('%s in %s:%d', $errstr, $errfile, $errline);

                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            }
        );

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $errors);

        return $result;
    }
}
