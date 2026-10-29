<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use Buckaroo\Shopware6\Handlers\PayPerEmailPaymentHandler;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\PayLinkService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * An empty or 0 "Due date (in days)" made the payment link expire the same day.
 * Like Paylink, it must send no expiry date, so Buckaroo's default applies.
 */
class PayPerEmailPaymentHandlerTest extends TestCase
{
    private const SALES_CHANNEL_ID = '98432def39fc4624b33213a56b8c944d';

    public function testEmptyOrZeroDaysSendsNoExpirationDate(): void
    {
        foreach ([null, '', 0, '0', -3] as $days) {
            $this->assertSame('', $this->expirationDate($days), 'Due date ' . var_export($days, true));
        }
    }

    public function testPositiveDaysGiveTodayPlusThoseDays(): void
    {
        $this->assertSame(date('Y-m-d', time() + 7 * 86400), $this->expirationDate(7));
        $this->assertSame(date('Y-m-d', time() + 14 * 86400), $this->expirationDate('14'));
    }

    private function expirationDate(mixed $days): string
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')
            ->with('payperemailExpireDays', self::SALES_CHANNEL_ID)
            ->willReturn($days);

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->settingsService = $settingsService;
        $asyncPaymentService->formatRequestParamService = $this->createMock(FormatRequestParamService::class);

        $handler = new PayPerEmailPaymentHandler($asyncPaymentService, $this->createMock(PayLinkService::class));

        $method = new \ReflectionMethod($handler, 'getExpirationDate');
        $method->setAccessible(true);

        return $method->invoke($handler, self::SALES_CHANNEL_ID);
    }
}
