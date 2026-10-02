<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Config\Config;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Only an explicit `test` environment may send a payment to the Buckaroo test endpoint.
 * A missing, empty or unknown environment is live, and methods without an environment
 * field of their own use the environment of the method they belong to.
 */
class ClientServiceTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    public function testLiveEnvironmentGivesLiveMode(): void
    {
        $client = $this->clientFor('ideal', ['idealEnvironment' => 'live']);

        $this->assertSame(Config::LIVE_MODE, $this->modeOf($client));
    }

    public function testTestEnvironmentGivesTestMode(): void
    {
        $client = $this->clientFor('ideal', ['idealEnvironment' => 'test']);

        $this->assertSame(Config::TEST_MODE, $this->modeOf($client));
    }

    /**
     * @dataProvider notTestEnvironments
     */
    public function testMissingEmptyOrUnknownEnvironmentDoesNotGiveTestMode(mixed $environment): void
    {
        $client = $this->clientFor('ideal', ['idealEnvironment' => $environment]);

        $this->assertSame(Config::LIVE_MODE, $this->modeOf($client));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notTestEnvironments(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'unknown' => ['sandbox'],
            'not scalar' => [['test']],
        ];
    }

    /**
     * iDEAL QR has no environment field since v2.8.0, but the installer still stores
     * `idealqrEnvironment = test`. That value must not keep iDEAL QR in test.
     */
    public function testIdealQrUsesTheIdealEnvironment(): void
    {
        $live = $this->clientFor('idealqr', ['idealEnvironment' => 'live', 'idealqrEnvironment' => 'test']);
        $test = $this->clientFor('idealqr', ['idealEnvironment' => 'test']);

        $this->assertSame(Config::LIVE_MODE, $this->modeOf($live));
        $this->assertSame(Config::TEST_MODE, $this->modeOf($test));
    }

    public function testIdealQrWithoutStoredEnvironmentIsNotTestMode(): void
    {
        $client = $this->clientFor('idealqr', []);

        $this->assertSame(Config::LIVE_MODE, $this->modeOf($client));
    }

    public function testKlarnaSliceItUsesTheKlarnaEnvironment(): void
    {
        $live = $this->clientFor('klarnain', ['klarnaEnvironment' => 'live', 'klarnainEnvironment' => 'test']);
        $test = $this->clientFor('klarnain', ['klarnaEnvironment' => 'test']);

        $this->assertSame(Config::LIVE_MODE, $this->modeOf($live));
        $this->assertSame(Config::TEST_MODE, $this->modeOf($test));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function clientFor(string $method, array $settings): Client
    {
        $settings += ['websiteKey' => 'website-key', 'secretKey' => 'secret-key'];

        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturnCallback(
            function (string $key, ?string $salesChannelId) use ($settings) {
                if (str_ends_with($key, 'Environment')) {
                    $this->assertSame(self::SALES_CHANNEL_ID, $salesChannelId);
                }

                return $settings[substr($key, strlen('BuckarooPayments.config.'))] ?? null;
            }
        );

        return (new ClientService(new SettingsService($systemConfigService), '6.7.0.0'))
            ->get($method, self::SALES_CHANNEL_ID);
    }

    private function modeOf(Client $client): string
    {
        $property = new \ReflectionProperty(Client::class, 'client');
        $property->setAccessible(true);

        /** @var \Buckaroo\BuckarooClient $buckarooClient */
        $buckarooClient = $property->getValue($client);

        return $buckarooClient->client()->config()->mode();
    }
}
