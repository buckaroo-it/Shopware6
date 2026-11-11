<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service\ErrorReporting;

use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReporter;
use Buckaroo\Shopware6\Service\ErrorReporting\ReportingLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReportingLoggerTest extends TestCase
{
    /** @var ErrorReporter&MockObject */
    private ErrorReporter $reporter;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $inner;

    private ReportingLogger $logger;

    protected function setUp(): void
    {
        $this->reporter = $this->createMock(ErrorReporter::class);
        $this->inner = $this->createMock(LoggerInterface::class);
        $this->logger = new ReportingLogger($this->inner, $this->reporter);
    }

    public function testErrorsAreLoggedAndReported(): void
    {
        $context = ['exception' => new \RuntimeException('boom')];

        $this->inner->expects($this->once())->method('log')->with('error', 'failed', $context);
        $this->reporter->expects($this->once())->method('reportLog')->with('error', 'failed', $context);

        $this->logger->error('failed', $context);
    }

    public function testLowerLevelsAreOnlyLogged(): void
    {
        $this->inner->expects($this->exactly(3))->method('log');
        $this->reporter->expects($this->never())->method('reportLog');

        $this->logger->warning('w', ['exception' => new \RuntimeException('boom')]);
        $this->logger->info('i');
        $this->logger->debug('d');
    }

    public function testReporterFailuresAreSwallowed(): void
    {
        $this->inner->expects($this->once())->method('log');
        $this->reporter->method('reportLog')->willThrowException(new \RuntimeException('reporter broken'));

        $this->logger->critical('failed', ['exception' => new \RuntimeException('boom')]);
    }
}
