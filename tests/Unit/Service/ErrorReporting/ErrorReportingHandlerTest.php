<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service\ErrorReporting;

use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReporter;
use Buckaroo\Shopware6\Service\ErrorReporting\ErrorReportingHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ErrorReportingHandlerTest extends TestCase
{
    /** @var ErrorReporter&MockObject */
    private ErrorReporter $reporter;

    private TestHandler $fileHandler;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->reporter = $this->createMock(ErrorReporter::class);
        $this->fileHandler = new TestHandler();
        // Same set-up as the "buckaroo_payment" logger service in services.yaml.
        $this->logger = new Logger('buckaroo_payment');
        $this->logger->pushHandler($this->fileHandler);
        $this->logger->pushHandler(new ErrorReportingHandler($this->reporter));
    }

    public function testErrorRecordsArePassedToTheReporterAndStillLogged(): void
    {
        $exception = new \RuntimeException('boom');

        $this->reporter->expects($this->once())
            ->method('reportLog')
            ->with('error', 'failed', ['exception' => $exception]);

        $this->logger->error('failed', ['exception' => $exception]);

        $this->assertTrue($this->fileHandler->hasErrorThatContains('failed'));
    }

    public function testCriticalRecordsArePassedToTheReporter(): void
    {
        $this->reporter->expects($this->once())->method('reportLog')->with('critical');

        $this->logger->critical('failed', [new \RuntimeException('boom')]);
    }

    public function testRecordsBelowErrorAreIgnored(): void
    {
        $this->reporter->expects($this->never())->method('reportLog');

        $this->logger->warning('careful', ['exception' => new \RuntimeException('boom')]);
        $this->logger->info('info');

        $this->assertTrue($this->fileHandler->hasWarningThatContains('careful'));
    }

    public function testReporterFailuresDoNotBreakLogging(): void
    {
        $this->reporter->method('reportLog')->willThrowException(new \RuntimeException('reporter broken'));

        $this->logger->error('failed', ['exception' => new \RuntimeException('boom')]);

        $this->assertTrue($this->fileHandler->hasErrorThatContains('failed'));
    }
}
