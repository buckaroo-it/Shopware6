<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Installers;

use Buckaroo\Shopware6\Installers\ErrorReportingInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ErrorReportingInstallerTest extends TestCase
{
    private const KEY = 'BuckarooPayments.config.errorReportingConsent';

    /** @var SystemConfigService&MockObject */
    private SystemConfigService $systemConfig;

    private ErrorReportingInstaller $installer;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with(SystemConfigService::class)->willReturn($this->systemConfig);
        $this->installer = new ErrorReportingInstaller($container);
    }

    public function testInstallMarksConsentAsNotAsked(): void
    {
        $this->systemConfig->method('get')->with(self::KEY)->willReturn(null);
        $this->systemConfig->expects($this->once())->method('set')->with(self::KEY, 'not_asked');

        $this->installer->install($this->createMock(InstallContext::class));
    }

    public function testUpdateMarksConsentAsNotAskedForExistingShops(): void
    {
        $this->systemConfig->method('get')->with(self::KEY)->willReturn(null);
        $this->systemConfig->expects($this->once())->method('set')->with(self::KEY, 'not_asked');

        $this->installer->update($this->createMock(UpdateContext::class));
    }

    /**
     * @dataProvider answeredProvider
     */
    public function testAnAnswerIsKept(string $answer): void
    {
        $this->systemConfig->method('get')->with(self::KEY)->willReturn($answer);
        $this->systemConfig->expects($this->never())->method('set');

        $this->installer->install($this->createMock(InstallContext::class));
        $this->installer->update($this->createMock(UpdateContext::class));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function answeredProvider(): array
    {
        return [
            'yes' => ['yes'],
            'no' => ['no'],
        ];
    }
}
