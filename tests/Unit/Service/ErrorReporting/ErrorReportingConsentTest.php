<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service\ErrorReporting;

use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReportingConsent;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ErrorReportingConsentTest extends TestCase
{
    /**
     * @dataProvider stateProvider
     */
    public function testState(mixed $stored, string $expectedState, bool $expectedGranted): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')
            ->with('BuckarooPayments.config.errorReportingConsent', null)
            ->willReturn($stored);
        $consent = new ErrorReportingConsent(new SettingsService($systemConfig));

        $this->assertSame($expectedState, $consent->getState());
        $this->assertSame($expectedGranted, $consent->isGranted());
    }

    /**
     * @return array<string, array{mixed, string, bool}>
     */
    public static function stateProvider(): array
    {
        return [
            'yes' => ['yes', 'yes', true],
            'no' => ['no', 'no', false],
            'not asked' => ['not_asked', 'not_asked', false],
            'missing' => [null, 'not_asked', false],
            'boolean true is not consent' => [true, 'not_asked', false],
            'unknown' => ['maybe', 'not_asked', false],
        ];
    }

    public function testUnreadableConfigIsNotConsent(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willThrowException(new \RuntimeException('database gone'));
        $consent = new ErrorReportingConsent(new SettingsService($systemConfig));

        $this->assertFalse($consent->isGranted());
    }
}
