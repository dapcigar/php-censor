<?php

declare(strict_types=1);

namespace Tests\PHPCensor\Logging;

use Exception;
use PHPCensor\Logging\BuildLogger;
use PHPCensor\Model\Build;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class BuildLoggerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var BuildLogger
     */
    protected $testedBuildLogger;

    protected $logger;

    protected $build;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->prophesize(LoggerInterface::class);
        $this->build = $this->prophesize(Build::class);

        $this->testedBuildLogger = new BuildLogger(
            $this->logger->reveal(),
            $this->build->reveal()
        );
    }

    public function testLog_CallsWrappedLogger(): void
    {
        $level = LogLevel::NOTICE;
        $message   = "Testing";
        $contextIn = [];

        $this->logger
            ->log($level, $message, Argument::type('array'))
            ->shouldBeCalledTimes(1);

        $this->testedBuildLogger->log($message, $level, $contextIn);
    }

    public function testLog_CallsWrappedLoggerForEachMessage(): void
    {
        $level     = LogLevel::NOTICE;
        $message   = ["One", "Two", "Three"];
        $contextIn = [];

        $this->logger
            ->log($level, "One", Argument::type('array'))
            ->shouldBeCalledTimes(1);

        $this->logger
            ->log($level, "Two", Argument::type('array'))
            ->shouldBeCalledTimes(1);

        $this->logger
            ->log($level, "Three", Argument::type('array'))
            ->shouldBeCalledTimes(1);

        $this->testedBuildLogger->log($message, $level, $contextIn);
    }

    public function testLogFailure_AddsExceptionContext(): void
    {
        $message = "Testing";

        $exception = new Exception("Expected Exception");


        $this->logger
            ->log(
                Argument::type('string'),
                Argument::type('string'),
                Argument::withEntry('exception', $exception)
            )
            ->shouldBeCalledTimes(1);

        $this->testedBuildLogger->logFailure($message, $exception);
    }
}
