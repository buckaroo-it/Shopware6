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

    public function testEmptyOrWhitespaceSettingsReturnNull(): void
    {
        $this->mockEnvironment('test');
        $this->mockSettings([
            'paypalExpressSandboxMerchantId' => '   ',
        ]);

        $this->assertNull($this->service->getMerchantId(self::SALES_CHANNEL_ID));
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
            'paypalExpressmerchantid'        => 'live-merchant-id',
            'paypalExpressSandboxMerchantId' => 'sandbox-merchant-id',
        ]);

        $this->assertSame(
            [
                'merchantId' => 'sandbox-merchant-id',
                'isTestMode' => true,
            ],
            $this->service->getCredentials(self::SALES_CHANNEL_ID)
        );
    }

    public function testGetCredentialsInLiveMode(): void
    {
        $this->mockEnvironment('live');
        $this->mockSettings([
            'paypalExpressmerchantid'        => 'live-merchant-id',
            'paypalExpressSandboxMerchantId' => 'sandbox-merchant-id',
        ]);

        $this->assertSame(
            [
                'merchantId' => 'live-merchant-id',
                'isTestMode' => false,
            ],
            $this->service->getCredentials(self::SALES_CHANNEL_ID)
        );
    }
}
