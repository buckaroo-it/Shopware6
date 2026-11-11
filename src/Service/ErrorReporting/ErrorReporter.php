<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\ErrorReporting;

use Buckaroo\Shopware6\Service\SettingsService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends exceptions logged by the plugin to the Buckaroo plugin log collector,
 * but only when the merchant opted in (see ErrorReportingConsent).
 *
 * Reports are buffered per request (deduplicated, at most MAX_REPORTS) and sent
 * in one call once the response has been sent (kernel.terminate), when a console
 * command or a message queue message finished, or at PHP shutdown as a fallback.
 *
 * Across requests, an exception thrown from the same place (class, code, file and
 * line; not the message, which often holds order ids) is sent at most once per
 * SUPPRESSION_WINDOW_SECONDS, tracked in a cache pool.
 *
 * Nothing in here may ever break the shop: every failure is swallowed, the HTTP
 * call has short timeouts and is never retried.
 */
class ErrorReporter implements EventSubscriberInterface
{
    public const ENDPOINT = 'https://plugin-logs.buckaroo.io/api/v1/reports';

    /**
     * Public, non-secret key identifying the plugin to the collector (like a Sentry DSN).
     */
    public const BUCKAROO_PLUGIN_LOG_INGEST_KEY = 'public-plugin-ingest-key';

    public const PLATFORM = 'shopware6';

    public const MAX_REPORTS = 20;

    public const MAX_TRACE_FRAMES = 50;

    public const MAX_PREVIOUS = 5;

    public const MAX_CONTEXT_KEYS = 20;

    public const MAX_MESSAGE_LENGTH = 2000;

    public const CONNECT_TIMEOUT = 2.0;

    public const TOTAL_TIMEOUT = 3.0;

    public const SUPPRESSION_WINDOW_SECONDS = 900;

    public const SUPPRESSION_KEY_PREFIX = 'buckaroo_error_report_';

    private const PLUGIN_PACKAGE = 'buckaroo/shopware6';

    /**
     * Log context keys that may be sent, mapped to their name in the report.
     * Everything else in the log context is dropped.
     */
    private const CONTEXT_KEYS = [
        'action' => 'action',
        'payment_method' => 'payment_method',
        'paymentMethod' => 'payment_method',
        'paymentCode' => 'payment_method',
        'paymentClass' => 'payment_class',
        'order_id' => 'order_id',
        'orderId' => 'order_id',
        'orderNumber' => 'order_number',
        'transactionId' => 'transaction_id',
        'statusCode' => 'status_code',
        'event_class' => 'event_class',
    ];

    private const LEVELS = [
        LogLevel::WARNING => 'warning',
        LogLevel::ERROR => 'error',
        LogLevel::CRITICAL => 'critical',
        LogLevel::ALERT => 'critical',
        LogLevel::EMERGENCY => 'critical',
    ];

    private HttpClientInterface $httpClient;

    private ErrorReportingConsent $consent;

    private SettingsService $settingsService;

    private ErrorReportScrubber $scrubber;

    private string $shopwareVersion;

    private string $shopUrl;

    private bool $useShutdownFallback;

    private ?CacheItemPoolInterface $suppressionCache;

    /**
     * @var \Closure(): int
     */
    private \Closure $clock;

    /**
     * @var list<array<string, mixed>>
     */
    private array $reports = [];

    /**
     * @var array<string, true>
     */
    private array $fingerprints = [];

    /**
     * Suppression fingerprints of the buffered reports, recorded when they are sent.
     *
     * @var array<string, true>
     */
    private array $suppressionFingerprints = [];

    private bool $shutdownRegistered = false;

    private bool $busy = false;

    private ?string $pluginVersion = null;

    public function __construct(
        HttpClientInterface $httpClient,
        ErrorReportingConsent $consent,
        SettingsService $settingsService,
        ErrorReportScrubber $scrubber,
        string $shopwareVersion,
        string $shopUrl,
        bool $useShutdownFallback = true,
        ?CacheItemPoolInterface $suppressionCache = null,
        ?\Closure $clock = null
    ) {
        $this->httpClient = $httpClient;
        $this->consent = $consent;
        $this->settingsService = $settingsService;
        $this->scrubber = $scrubber;
        $this->shopwareVersion = $shopwareVersion;
        $this->shopUrl = $shopUrl;
        $this->useShutdownFallback = $useShutdownFallback;
        $this->suppressionCache = $suppressionCache;
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['flush', -1024],
            ConsoleEvents::TERMINATE => ['flush', -1024],
            // Referenced by name so the reporter does not depend on symfony/messenger.
            'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent' => ['flush', -1024],
            'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent' => ['flush', -1024],
        ];
    }

    /**
     * Entry point for the plugin's loggers: reports the exception found in the
     * log context, if any. Log records without an exception are ignored.
     *
     * @param array<mixed> $context
     */
    public function reportLog(string $level, string $message, array $context = []): void
    {
        try {
            $exception = $this->findException($context);
            if ($exception === null) {
                return;
            }
            $this->report($exception, $level, $message, $context);
        } catch (\Throwable $th) {
            // Never let error reporting break the shop.
        }
    }

    /**
     * @param array<mixed> $context
     */
    public function report(
        \Throwable $exception,
        string $level = LogLevel::ERROR,
        ?string $message = null,
        array $context = []
    ): void {
        if ($this->busy || count($this->reports) >= self::MAX_REPORTS) {
            return;
        }

        $this->busy = true;
        try {
            if (!$this->consent->isGranted()) {
                return;
            }

            $fingerprint = sha1(
                get_class($exception) . '|' . $exception->getMessage() . '|'
                . $exception->getFile() . '|' . $exception->getLine()
            );
            if (isset($this->fingerprints[$fingerprint])) {
                return;
            }
            $this->fingerprints[$fingerprint] = true;

            $suppressionFingerprint = $this->getSuppressionFingerprint($exception);
            if ($this->isSuppressed($suppressionFingerprint)) {
                return;
            }

            $this->reports[] = $this->buildReport($exception, $level, $message, $context);
            $this->suppressionFingerprints[$suppressionFingerprint] = true;
            $this->registerShutdownFallback();
        } catch (\Throwable $th) {
            // Never let error reporting break the shop.
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Sends the buffered reports, if any, in a single request and empties the buffer.
     */
    public function flush(): void
    {
        if ($this->reports === [] || $this->busy) {
            return;
        }

        $reports = $this->reports;
        $suppressionFingerprints = array_keys($this->suppressionFingerprints);
        $this->reports = [];
        $this->fingerprints = [];
        $this->suppressionFingerprints = [];

        $this->busy = true;
        try {
            // Consent may have been revoked during the request.
            if (!$this->consent->isGranted()) {
                return;
            }

            $body = json_encode(
                $this->buildPayload($reports),
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            if ($body === false) {
                return;
            }

            // Recorded before sending: the response is not waited for, and a
            // failed send is not retried either.
            $this->recordSent($suppressionFingerprints);

            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Buckaroo-Ingest-Key' => self::BUCKAROO_PLUGIN_LOG_INGEST_KEY,
                    'User-Agent' => 'buckaroo-' . self::PLATFORM . '/' . $this->getPluginVersion(),
                ],
                'body' => $body,
                // Inactivity timeout, which also bounds connecting.
                'timeout' => self::CONNECT_TIMEOUT,
                'max_duration' => self::TOTAL_TIMEOUT,
                'max_redirects' => 0,
            ]);
            // The response is not used, but waiting for it completes the request.
            $response->getStatusCode();
        } catch (\Throwable $th) {
            // Never let error reporting break the shop; no retries.
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Called by register_shutdown_function, for requests that never reach kernel.terminate.
     */
    public function flushOnShutdown(): void
    {
        if ($this->reports === []) {
            return;
        }

        try {
            if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        } catch (\Throwable $th) {
            // Sending anyway.
        }

        $this->flush();
    }

    /**
     * Same place in the code, regardless of the message.
     */
    private function getSuppressionFingerprint(\Throwable $exception): string
    {
        return sha1(
            get_class($exception) . '|' . $exception->getCode() . '|'
            . $exception->getFile() . '|' . $exception->getLine()
        );
    }

    /**
     * Whether this exception was sent within the window. Any cache failure
     * means "not suppressed", so the report is sent.
     */
    private function isSuppressed(string $suppressionFingerprint): bool
    {
        if ($this->suppressionCache === null) {
            return false;
        }

        try {
            $item = $this->suppressionCache->getItem(self::SUPPRESSION_KEY_PREFIX . $suppressionFingerprint);
            if (!$item->isHit()) {
                return false;
            }
            $sentAt = $item->get();

            return is_int($sentAt) && ($this->clock)() - $sentAt < self::SUPPRESSION_WINDOW_SECONDS;
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * @param list<string> $suppressionFingerprints
     */
    private function recordSent(array $suppressionFingerprints): void
    {
        $cache = $this->suppressionCache;
        if ($cache === null) {
            return;
        }

        foreach ($suppressionFingerprints as $suppressionFingerprint) {
            try {
                $item = $cache->getItem(self::SUPPRESSION_KEY_PREFIX . $suppressionFingerprint);
                $item->set(($this->clock)());
                $item->expiresAfter(self::SUPPRESSION_WINDOW_SECONDS);
                $cache->save($item);
            } catch (\Throwable $th) {
                // Not suppressing next time is fine.
            }
        }
    }

    /**
     * @param array<mixed> $context
     */
    private function findException(array $context): ?\Throwable
    {
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            return $context['exception'];
        }

        foreach ($context as $value) {
            if ($value instanceof \Throwable) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildReport(\Throwable $exception, string $level, ?string $message, array $context): array
    {
        $report = [
            'occurred_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'level' => self::LEVELS[strtolower($level)] ?? 'error',
        ];

        if ($message !== null && trim($message) !== '') {
            $report['message'] = $this->scrubMessage($message);
        }

        $report['exception'] = $this->buildException($exception);

        $reportContext = $this->buildContext($context);
        if ($reportContext !== []) {
            $report['context'] = $reportContext;
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildException(\Throwable $exception): array
    {
        $previous = [];
        $current = $exception->getPrevious();
        while ($current !== null && count($previous) < self::MAX_PREVIOUS) {
            $previous[] = [
                'class' => get_class($current),
                'message' => $this->scrubMessage($current->getMessage()),
                'code' => (string) $current->getCode(),
                'file' => $current->getFile(),
                'line' => $current->getLine(),
            ];
            $current = $current->getPrevious();
        }

        return [
            'class' => get_class($exception),
            'message' => $this->scrubMessage($exception->getMessage()),
            'code' => (string) $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->buildTrace($exception),
            'previous' => $previous,
        ];
    }

    /**
     * Stack trace without arguments.
     *
     * @return list<array{file: string, line: int, class: string, function: string}>
     */
    private function buildTrace(\Throwable $exception): array
    {
        $trace = [];
        foreach (array_slice($exception->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $trace[] = [
                'file' => $frame['file'] ?? '',
                'line' => $frame['line'] ?? 0,
                'class' => $frame['class'] ?? '',
                'function' => $frame['function'],
            ];
        }

        return $trace;
    }

    /**
     * Flat string map of the allow-listed log context keys.
     *
     * @param array<mixed> $context
     *
     * @return array<string, string>
     */
    private function buildContext(array $context): array
    {
        $result = [];
        foreach (self::CONTEXT_KEYS as $logKey => $reportKey) {
            if (count($result) >= self::MAX_CONTEXT_KEYS) {
                break;
            }
            if (isset($result[$reportKey]) || !isset($context[$logKey]) || !is_scalar($context[$logKey])) {
                continue;
            }
            $result[$reportKey] = mb_substr($this->scrubber->scrubString((string) $context[$logKey]), 0, 255);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $reports
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $reports): array
    {
        $payload = [
            'platform' => self::PLATFORM,
            'plugin_version' => $this->getPluginVersion(),
            'platform_version' => $this->shopwareVersion,
            'php_version' => PHP_VERSION,
            'environment' => $this->getEnvironment(),
            'shop_url' => $this->shopUrl,
        ];

        $websiteKey = $this->getWebsiteKey();
        if ($websiteKey !== '') {
            $payload['website_key'] = $websiteKey;
        }

        $payload['reports'] = $reports;

        return $payload;
    }

    private function scrubMessage(string $message): string
    {
        return mb_substr($this->scrubber->scrubString($message), 0, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Environments are configured per payment method; the shop counts as live
     * as soon as one payment method is set to live.
     */
    private function getEnvironment(): string
    {
        try {
            $values = $this->settingsService->systemConfigService->getDomain('BuckarooPayments.config', null, true);
            foreach ($values as $key => $value) {
                if (
                    str_ends_with((string) $key, 'Environment') &&
                    is_string($value) &&
                    strtolower(trim($value)) === 'live'
                ) {
                    return 'live';
                }
            }
        } catch (\Throwable $th) {
            // Fall through to "test".
        }

        return 'test';
    }

    private function getWebsiteKey(): string
    {
        try {
            return trim($this->settingsService->getSettingAsString('websiteKey'));
        } catch (\Throwable $th) {
            return '';
        }
    }

    private function getPluginVersion(): string
    {
        if ($this->pluginVersion !== null) {
            return $this->pluginVersion;
        }

        $version = 'unknown';
        try {
            $composerFile = dirname(__DIR__, 3) . '/composer.json';
            $composer = is_readable($composerFile)
                ? json_decode((string) file_get_contents($composerFile), true)
                : null;
            if (is_array($composer) && isset($composer['version']) && is_string($composer['version'])) {
                $version = $composer['version'];
            } elseif (
                class_exists(\Composer\InstalledVersions::class) &&
                \Composer\InstalledVersions::isInstalled(self::PLUGIN_PACKAGE)
            ) {
                $version = \Composer\InstalledVersions::getPrettyVersion(self::PLUGIN_PACKAGE) ?? $version;
            }
        } catch (\Throwable $th) {
            // Keep "unknown".
        }

        return $this->pluginVersion = $version;
    }

    private function registerShutdownFallback(): void
    {
        if ($this->shutdownRegistered || !$this->useShutdownFallback) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function([$this, 'flushOnShutdown']);
    }
}
