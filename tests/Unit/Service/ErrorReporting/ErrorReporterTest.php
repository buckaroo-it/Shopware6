<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service\ErrorReporting;

use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReporter;
use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReportingConsent;
use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReportScrubber;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ErrorReporterTest extends TestCase
{
    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private array $requests = [];

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->config = [
            'BuckarooPayments.config.errorReportingConsent' => 'yes',
            'BuckarooPayments.config.websiteKey' => 'WEBSITEKEY1',
            'BuckarooPayments.config.secretKey' => 'super-secret',
        ];
    }

    public function testNothingIsSentWhenConsentIsNotAsked(): void
    {
        $this->config['BuckarooPayments.config.errorReportingConsent'] = 'not_asked';
        $reporter = $this->createReporter();

        $reporter->report(new \RuntimeException('boom'));
        $reporter->flush();

        $this->assertSame([], $this->requests);
    }

    public function testNothingIsSentWhenConsentIsNo(): void
    {
        $this->config['BuckarooPayments.config.errorReportingConsent'] = 'no';
        $reporter = $this->createReporter();

        $reporter->report(new \RuntimeException('boom'));
        $reporter->flush();

        $this->assertSame([], $this->requests);
    }

    public function testNothingIsSentWhenConsentIsMissingOrUnknown(): void
    {
        unset($this->config['BuckarooPayments.config.errorReportingConsent']);
        $reporter = $this->createReporter();
        $reporter->report(new \RuntimeException('boom'));
        $reporter->flush();

        $this->config['BuckarooPayments.config.errorReportingConsent'] = 'YES';
        $reporter = $this->createReporter();
        $reporter->report(new \RuntimeException('boom'));
        $reporter->flush();

        $this->assertSame([], $this->requests);
    }

    public function testNothingIsSentWhenConsentIsRevokedBeforeFlush(): void
    {
        $reporter = $this->createReporter();
        $reporter->report(new \RuntimeException('boom'));

        $this->config['BuckarooPayments.config.errorReportingConsent'] = 'no';
        $reporter->flush();

        $this->assertSame([], $this->requests);
    }

    public function testNothingIsSentWithoutReports(): void
    {
        $this->createReporter()->flush();

        $this->assertSame([], $this->requests);
    }

    public function testPayloadShape(): void
    {
        $this->config['BuckarooPayments.config.idealEnvironment'] = 'live';
        $reporter = $this->createReporter();

        $previous = new \LogicException('inner', 7);
        $exception = new \RuntimeException('outer', 42, $previous);
        $reporter->reportLog('critical', 'Payment failed', [
            'exception' => $exception,
            'orderId' => 'order-1',
            'paymentMethod' => 'ideal',
            'customerEmail' => 'john@example.com',
            'trace' => 'should not be sent',
        ]);
        $reporter->flush();

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame(ErrorReporter::ENDPOINT, $request['url']);

        $headers = $this->getHeaders($request['options']);
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertSame(ErrorReporter::BUCKAROO_PLUGIN_LOG_INGEST_KEY, $headers['x-buckaroo-ingest-key']);
        $this->assertMatchesRegularExpression('#^buckaroo-shopware6/\S+$#', $headers['user-agent']);

        $payload = $this->getPayload($request['options']);
        $this->assertSame('shopware6', $payload['platform']);
        $this->assertIsString($payload['plugin_version']);
        $this->assertNotSame('', $payload['plugin_version']);
        $this->assertSame('6.6.10.0', $payload['platform_version']);
        $this->assertSame(PHP_VERSION, $payload['php_version']);
        $this->assertSame('live', $payload['environment']);
        $this->assertSame('https://shop.example.com', $payload['shop_url']);
        $this->assertSame('WEBSITEKEY1', $payload['website_key']);
        $this->assertStringNotContainsString('super-secret', (string) json_encode($payload));

        $this->assertCount(1, $payload['reports']);
        $report = $payload['reports'][0];
        $this->assertSame('critical', $report['level']);
        $this->assertSame('Payment failed', $report['message']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $report['occurred_at']));
        $this->assertEquals(['order_id' => 'order-1', 'payment_method' => 'ideal'], $report['context']);

        $reportedException = $report['exception'];
        $this->assertSame(\RuntimeException::class, $reportedException['class']);
        $this->assertSame('outer', $reportedException['message']);
        $this->assertSame('42', $reportedException['code']);
        $this->assertSame(__FILE__, $reportedException['file']);
        $this->assertIsInt($reportedException['line']);
        $this->assertNotEmpty($reportedException['trace']);
        $this->assertLessThanOrEqual(ErrorReporter::MAX_TRACE_FRAMES, count($reportedException['trace']));
        foreach ($reportedException['trace'] as $frame) {
            $this->assertSame(['file', 'line', 'class', 'function'], array_keys($frame));
        }
        $this->assertSame([[
            'class' => \LogicException::class,
            'message' => 'inner',
            'code' => '7',
            'file' => __FILE__,
            'line' => $previous->getLine(),
        ]], $reportedException['previous']);
    }

    public function testEnvironmentIsTestWithoutLivePaymentMethods(): void
    {
        $this->config['BuckarooPayments.config.idealEnvironment'] = 'test';
        unset($this->config['BuckarooPayments.config.websiteKey']);
        $reporter = $this->createReporter();

        $reporter->report(new \RuntimeException('boom'));
        $reporter->flush();

        $payload = $this->getPayload($this->requests[0]['options']);
        $this->assertSame('test', $payload['environment']);
        $this->assertArrayNotHasKey('website_key', $payload);
    }

    public function testTraceHasNoArgumentsAndIsLimited(): void
    {
        $reporter = $this->createReporter();

        $reporter->report($this->createDeepException(80, 'secret-argument'));
        $reporter->flush();

        $payload = $this->getPayload($this->requests[0]['options']);
        $trace = $payload['reports'][0]['exception']['trace'];
        $this->assertCount(ErrorReporter::MAX_TRACE_FRAMES, $trace);
        $this->assertStringNotContainsString('secret-argument', (string) json_encode($payload));
    }

    public function testPreviousExceptionsAreLimited(): void
    {
        $reporter = $this->createReporter();
        $exception = null;
        for ($i = 0; $i < 10; $i++) {
            $exception = new \RuntimeException('level ' . $i, 0, $exception);
        }
        $this->assertInstanceOf(\RuntimeException::class, $exception);

        $reporter->report($exception);
        $reporter->flush();

        $payload = $this->getPayload($this->requests[0]['options']);
        $this->assertCount(ErrorReporter::MAX_PREVIOUS, $payload['reports'][0]['exception']['previous']);
    }

    public function testIdenticalExceptionsAreDeduplicatedAndReportsAreCapped(): void
    {
        $reporter = $this->createReporter();

        $same = new \RuntimeException('same');
        $reporter->report($same);
        $reporter->report($same);
        for ($i = 0; $i < 30; $i++) {
            $reporter->report(new \RuntimeException('different ' . $i));
        }
        $reporter->flush();

        $this->assertCount(1, $this->requests);
        $payload = $this->getPayload($this->requests[0]['options']);
        $this->assertCount(ErrorReporter::MAX_REPORTS, $payload['reports']);
        $this->assertSame('same', $payload['reports'][0]['exception']['message']);
        $this->assertSame('different 0', $payload['reports'][1]['exception']['message']);
    }

    public function testBufferIsEmptiedAfterFlush(): void
    {
        $reporter = $this->createReporter();
        $exception = new \RuntimeException('boom');

        $reporter->report($exception);
        $reporter->flush();
        $reporter->flush();
        // A new request may report the same exception again.
        $reporter->report($exception);
        $reporter->flush();

        $this->assertCount(2, $this->requests);
    }

    public function testLogRecordsWithoutExceptionAreIgnored(): void
    {
        $reporter = $this->createReporter();

        $reporter->reportLog('error', 'Something failed: ' . 'boom', ['orderId' => '1']);
        $reporter->flush();

        $this->assertSame([], $this->requests);
    }

    public function testExceptionIsFoundUnderAnyContextKey(): void
    {
        $reporter = $this->createReporter();

        $reporter->reportLog('error', 'failed', [new \RuntimeException('positional')]);
        $reporter->flush();

        $payload = $this->getPayload($this->requests[0]['options']);
        $this->assertSame('positional', $payload['reports'][0]['exception']['message']);
        $this->assertSame('error', $payload['reports'][0]['level']);
    }

    public function testSensitiveDataIsScrubbed(): void
    {
        $reporter = $this->createReporter();

        $reporter->reportLog(
            'error',
            'Customer john.doe@example.com failed',
            [
                'exception' => new \RuntimeException(
                    'Card 4111 1111 1111 1111 with IBAN NL91ABNA0417164300 rejected, password=hunter2'
                ),
                'orderId' => 'jane@example.com',
            ]
        );
        $reporter->flush();

        $body = $this->requests[0]['options']['body'];
        $this->assertIsString($body);
        $leaks = ['john.doe@example.com', 'jane@example.com', '4111 1111 1111 1111', 'NL91ABNA0417164300', 'hunter2'];
        foreach ($leaks as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $payload = $this->getPayload($this->requests[0]['options']);
        $this->assertSame('Customer [EMAIL] failed', $payload['reports'][0]['message']);
        $this->assertSame(
            'Card [CARD] with IBAN [IBAN] rejected, password=[FILTERED]',
            $payload['reports'][0]['exception']['message']
        );
    }

    public function testShortTimeoutsAndNoRedirects(): void
    {
        $reporter = $this->createReporter();

        $reporter->report(new \RuntimeException('boom'));
        $reporter->flush();

        $options = $this->requests[0]['options'];
        $this->assertLessThanOrEqual(2.0, $options['timeout']);
        $this->assertLessThanOrEqual(3.0, $options['max_duration']);
        $this->assertSame(0, $options['max_redirects']);
    }

    public function testTransportFailuresAreSwallowed(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            throw new \RuntimeException('network down');
        });
        $reporter = $this->createReporter($client);

        $reporter->report(new \RuntimeException('boom'));
        $reporter->flush();

        $this->addToAssertionCount(1);
    }

    public function testErrorResponsesAreSwallowed(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"message":"invalid"}', ['http_code' => 422]),
            new MockResponse('', ['error' => 'timeout']),
        ]);
        $reporter = $this->createReporter($client);

        $reporter->report(new \RuntimeException('first'));
        $reporter->flush();
        $reporter->report(new \RuntimeException('second'));
        $reporter->flush();

        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testConfigFailuresAreSwallowedAndNothingIsSent(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willThrowException(new \RuntimeException('database gone'));
        $settings = new SettingsService($systemConfig);
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse('', ['http_code' => 202]);
        });
        $reporter = new ErrorReporter(
            $client,
            new ErrorReportingConsent($settings),
            $settings,
            new ErrorReportScrubber(),
            '6.6.10.0',
            'https://shop.example.com',
            false
        );

        $reporter->reportLog('error', 'failed', ['exception' => new \RuntimeException('boom')]);
        $reporter->flush();

        $this->assertSame([], $this->requests);
    }

    public function testSameExceptionInALaterRequestWithinTheWindowIsNotSent(): void
    {
        $cache = new ArrayAdapter();

        $this->sendInNewRequest($cache, $this->createSameSpotException('first'));
        $this->now += ErrorReporter::SUPPRESSION_WINDOW_SECONDS - 1;
        $this->sendInNewRequest($cache, $this->createSameSpotException('first'));

        $this->assertCount(1, $this->requests);
    }

    public function testSameExceptionIsSentAgainAfterTheWindow(): void
    {
        $cache = new ArrayAdapter();

        $this->sendInNewRequest($cache, $this->createSameSpotException('first'));
        $this->now += ErrorReporter::SUPPRESSION_WINDOW_SECONDS;
        $this->sendInNewRequest($cache, $this->createSameSpotException('first'));

        $this->assertCount(2, $this->requests);
    }

    public function testSameClassOnAnotherLineIsStillSent(): void
    {
        $cache = new ArrayAdapter();

        $this->sendInNewRequest($cache, new \RuntimeException('boom'));
        $this->sendInNewRequest($cache, new \RuntimeException('boom'));

        $this->assertCount(2, $this->requests);
    }

    public function testDifferentMessageFromTheSameSpotIsSuppressed(): void
    {
        $cache = new ArrayAdapter();

        $this->sendInNewRequest($cache, $this->createSameSpotException('Order 100000123 failed'));
        $this->sendInNewRequest($cache, $this->createSameSpotException('Order 100000456 failed'));

        $this->assertCount(1, $this->requests);
    }

    public function testDifferentCodeFromTheSameSpotIsStillSent(): void
    {
        $cache = new ArrayAdapter();

        $this->sendInNewRequest($cache, $this->createSameSpotException('boom', 1));
        $this->sendInNewRequest($cache, $this->createSameSpotException('boom', 2));

        $this->assertCount(2, $this->requests);
    }

    public function testSuppressionIsRecordedOnlyWhenSentWithConsent(): void
    {
        $cache = new ArrayAdapter();

        $reporter = $this->createReporter(null, $cache);
        $reporter->report($this->createSameSpotException('boom'));
        $this->config['BuckarooPayments.config.errorReportingConsent'] = 'no';
        $reporter->flush();
        $this->assertSame([], $this->getStoredSuppressions($cache));

        $this->config['BuckarooPayments.config.errorReportingConsent'] = 'yes';
        $this->sendInNewRequest($cache, $this->createSameSpotException('boom'));

        $this->assertCount(1, $this->requests);
        $this->assertCount(1, $this->getStoredSuppressions($cache));
    }

    public function testSuppressionIsRecordedEvenWhenTheSendFails(): void
    {
        $cache = new ArrayAdapter();
        $client = new MockHttpClient(function (): MockResponse {
            throw new \RuntimeException('network down');
        });

        $reporter = $this->createReporter($client, $cache);
        $reporter->report($this->createSameSpotException('boom'));
        $reporter->flush();

        $this->assertCount(1, $this->getStoredSuppressions($cache));
        $key = array_key_first($this->getStoredSuppressions($cache));
        $this->assertIsString($key);
        $this->assertStringStartsWith(ErrorReporter::SUPPRESSION_KEY_PREFIX, $key);
    }

    public function testCacheFailuresAreSwallowedAndTheReportIsSent(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('redis down'));
        $cache->method('save')->willThrowException(new \RuntimeException('redis down'));

        $this->sendInNewRequest($cache, $this->createSameSpotException('boom'));
        $this->sendInNewRequest($cache, $this->createSameSpotException('boom'));

        $this->assertCount(2, $this->requests);
    }

    public function testSubscribesToEndOfRequestEvents(): void
    {
        $events = ErrorReporter::getSubscribedEvents();

        $this->assertArrayHasKey('kernel.terminate', $events);
        $this->assertArrayHasKey('console.terminate', $events);
    }

    private function createReporter(
        ?HttpClientInterface $client = null,
        ?CacheItemPoolInterface $cache = null
    ): ErrorReporter {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            fn (string $key) => $this->config[$key] ?? null
        );
        $systemConfig->method('getDomain')->willReturnCallback(fn () => $this->config);
        $settings = new SettingsService($systemConfig);

        $client ??= new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse('{"accepted":1}', ['http_code' => 202]);
        });

        return new ErrorReporter(
            $client,
            new ErrorReportingConsent($settings),
            $settings,
            new ErrorReportScrubber(),
            '6.6.10.0',
            'https://shop.example.com',
            false,
            $cache,
            fn (): int => $this->now
        );
    }

    /**
     * One request: a fresh reporter (empty per-request buffer) sharing the cache.
     */
    private function sendInNewRequest(CacheItemPoolInterface $cache, \Throwable $exception): void
    {
        $reporter = $this->createReporter(null, $cache);
        $reporter->report($exception);
        $reporter->flush();
    }

    /**
     * Cache entries holding a value; ArrayAdapter also lists looked-up misses.
     *
     * @return array<string, mixed>
     */
    private function getStoredSuppressions(ArrayAdapter $cache): array
    {
        return array_filter($cache->getValues(), static fn ($value): bool => $value !== null);
    }

    /**
     * Always thrown from the same file and line.
     */
    private function createSameSpotException(string $message, int $code = 0): \RuntimeException
    {
        return new \RuntimeException($message, $code);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function getPayload(array $options): array
    {
        $this->assertIsString($options['body']);
        $payload = json_decode($options['body'], true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private function getHeaders(array $options): array
    {
        $headers = [];
        $this->assertIsArray($options['headers']);
        foreach ($options['headers'] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    private function createDeepException(int $depth, string $argument): \RuntimeException
    {
        if ($depth === 0) {
            return new \RuntimeException('deep');
        }

        return $this->createDeepException($depth - 1, $argument);
    }
}
