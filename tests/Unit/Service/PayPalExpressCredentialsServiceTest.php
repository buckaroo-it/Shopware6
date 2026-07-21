<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\PayPalExpressCredentialsService;

class PayPalExpressCredentialsServiceTest extends TestCase
{
    private PayPalExpressCredentialsService $service;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    private const SALES_CHANNEL_ID = 'test-sales-channel-id';

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->service = new PayPalExpressCredentialsService($this->settingsService);
    }

    private function mockEnvironment(string $environment): void
    {
        $this->settingsService
            ->method('getEnvironment')
            ->with('paypal', self::SALES_CHANNEL_ID)
            ->willReturn($environment);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function mockSettings(array $settings): void
    {
        $this->settingsService
            ->method('getSetting')
            ->willReturnCallback(
                function (string $setting) use ($settings) {
                    return $settings[$setting] ?? null;
                }
            );
    }

    public function testIsTestModeWhenEnvironmentIsTest(): void
    {
        $this->mockEnvironment('test');

        $this->assertTrue($this->service->isTestMode(self::SALES_CHANNEL_ID));
    }

    public function testIsNotTestModeWhenEnvironmentIsLive(): void
    {
        $this->mockEnvironment('live');

        $this->assertFalse($this->service->isTestMode(self::SALES_CHANNEL_ID));
    }

    public function testLiveModeReturnsLiveMerchantId(): void
    {
        $this->mockEnvironment('live');
        $this->mockSettings([
            'paypalExpressmerchantid'        => 'live-merchant-id',
            'paypalExpressSandboxMerchantId' => 'sandbox-merchant-id',
        ]);

        $this->assertSame(
            'live-merchant-id',
            $this->service->getMerchantId(self::SALES_CHANNEL_ID)
        );
    }

    public function testLiveModeReturnsNullClientIdsSoSdkDefaultsAreUsed(): void
    {
        $this->mockEnvironment('live');
        $this->mockSettings([
            'paypalExpressClientIdTest'           => 'test-client-id',
            'paypalExpressCollectingClientIdTest' => 'test-collecting-client-id',
        ]);

        $this->assertNull($this->service->getClientId(self::SALES_CHANNEL_ID));
        $this->assertNull($this->service->getCollectingClientId(self::SALES_CHANNEL_ID));
    }

    public function testTestModeReturnsSandboxMerchantId(): void
    {
        $this->mockEnvironment('test');
        $this->mockSettings([
            'paypalExpressmerchantid'        => 'live-merchant-id',
            'paypalExpressSandboxMerchantId' => 'sandbox-merchant-id',
        ]);

        $this->assertSame(
            'sandbox-merchant-id',
            $this->service->getMerchantId(self::SALES_CHANNEL_ID)
        );
    }

    public function testTestModeNeverReturnsLiveMerchantId(): void
    {
        $this->mockEnvironment('test');
        $this->mockSettings([
            'paypalExpressmerchantid' => 'live-merchant-id',
        ]);

        $this->assertNull($this->service->getMerchantId(self::SALES_CHANNEL_ID));
    }

    public function testTestModeReturnsConfiguredTestClientIds(): void
    {
        $this->mockEnvironment('test');
        $this->mockSettings([
            'paypalExpressClientIdTest'           => 'test-client-id',
            'paypalExpressCollectingClientIdTest' => 'test-collecting-client-id',
        ]);

        $this->assertSame(
            'test-client-id',
            $this->service->getClientId(self::SALES_CHANNEL_ID)
        );
        $this->assertSame(
            'test-collecting-client-id',
            $this->service->getCollectingClientId(self::SALES_CHANNEL_ID)
        );
    }

    public function testEmptyOrWhitespaceSettingsReturnNull(): void
    {
        $this->mockEnvironment('test');
        $this->mockSettings([
            'paypalExpressSandboxMerchantId'      => '   ',
            'paypalExpressClientIdTest'           => '',
            'paypalExpressCollectingClientIdTest' => null,
        ]);

        $this->assertNull($this->service->getMerchantId(self::SALES_CHANNEL_ID));
        $this->assertNull($this->service->getClientId(self::SALES_CHANNEL_ID));
        $this->assertNull($this->service->getCollectingClientId(self::SALES_CHANNEL_ID));
    }

    public function testValuesAreTrimmed(): void
    {
        $this->mockEnvironment('test');
        $this->mockSettings([
            'paypalExpressSandboxMerchantId' => '  sandbox-merchant-id  ',
        ]);

        $this->assertSame(
            'sandbox-merchant-id',
            $this->service->getMerchantId(self::SALES_CHANNEL_ID)
        );
    }

    public function testGetCredentialsInTestMode(): void
    {
        $this->mockEnvironment('test');
        $this->mockSettings([
            'paypalExpressmerchantid'             => 'live-merchant-id',
            'paypalExpressSandboxMerchantId'      => 'sandbox-merchant-id',
            'paypalExpressClientIdTest'           => 'test-client-id',
            'paypalExpressCollectingClientIdTest' => 'test-collecting-client-id',
        ]);

        $this->assertSame(
            [
                'merchantId'         => 'sandbox-merchant-id',
                'clientId'           => 'test-client-id',
                'collectingClientId' => 'test-collecting-client-id',
                'isTestMode'         => true,
            ],
            $this->service->getCredentials(self::SALES_CHANNEL_ID)
        );
    }

    public function testGetCredentialsInLiveMode(): void
    {
        $this->mockEnvironment('live');
        $this->mockSettings([
            'paypalExpressmerchantid'             => 'live-merchant-id',
            'paypalExpressSandboxMerchantId'      => 'sandbox-merchant-id',
            'paypalExpressClientIdTest'           => 'test-client-id',
            'paypalExpressCollectingClientIdTest' => 'test-collecting-client-id',
        ]);

        $this->assertSame(
            [
                'merchantId'         => 'live-merchant-id',
                'clientId'           => null,
                'collectingClientId' => null,
                'isTestMode'         => false,
            ],
            $this->service->getCredentials(self::SALES_CHANNEL_ID)
        );
    }
}
