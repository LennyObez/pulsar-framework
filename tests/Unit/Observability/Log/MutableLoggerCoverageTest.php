<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Observability\Log\MutableLogger;
use RuntimeException;

#[CoversClass(MutableLogger::class)]
final class MutableLoggerCoverageTest extends TestCase
{
    #[Test]
    public function setMinLevelFiltersMessages(): void
    {
        $written = [];
        $sink = $this->createSink($written);

        $logger = new MutableLogger([$sink], LogLevel::Warning);

        $logger->debug('debug msg');
        $logger->info('info msg');
        $logger->warning('warning msg');
        $logger->error('error msg');

        self::assertCount(2, $written);
        self::assertSame('warning msg', $written[0]->message);
        self::assertSame('error msg', $written[1]->message);
    }

    #[Test]
    public function minLevelReturnsCurrentThreshold(): void
    {
        $logger = new MutableLogger([], LogLevel::Info);

        self::assertSame(LogLevel::Info, $logger->minLevel());

        $logger->setMinLevel(LogLevel::Error);
        self::assertSame(LogLevel::Error, $logger->minLevel());
    }

    #[Test]
    public function allPsrMethodsDispatchCorrectly(): void
    {
        $written = [];
        $sink = $this->createSink($written);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->emergency('emergency');
        $logger->alert('alert');
        $logger->critical('critical');
        $logger->error('error');
        $logger->warning('warning');
        $logger->notice('notice');
        $logger->info('info');
        $logger->debug('debug');

        self::assertCount(8, $written);
        self::assertSame(LogLevel::Emergency, $written[0]->level);
        self::assertSame(LogLevel::Alert, $written[1]->level);
        self::assertSame(LogLevel::Critical, $written[2]->level);
        self::assertSame(LogLevel::Error, $written[3]->level);
        self::assertSame(LogLevel::Warning, $written[4]->level);
        self::assertSame(LogLevel::Notice, $written[5]->level);
        self::assertSame(LogLevel::Info, $written[6]->level);
        self::assertSame(LogLevel::Debug, $written[7]->level);
    }

    #[Test]
    public function logWithStringLevel(): void
    {
        $written = [];
        $sink = $this->createSink($written);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->log('error', 'string level message');

        self::assertCount(1, $written);
        self::assertSame(LogLevel::Error, $written[0]->level);
    }

    #[Test]
    public function logWithLogLevelEnum(): void
    {
        $written = [];
        $sink = $this->createSink($written);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->log(LogLevel::Warning, 'enum level message');

        self::assertCount(1, $written);
        self::assertSame(LogLevel::Warning, $written[0]->level);
    }

    #[Test]
    public function sinkExceptionDoesNotPropagateInNonDebugMode(): void
    {
        $failingSink = new class implements LogSinkInterface {
            public function write(LogEntry $entry): void
            {
                throw new RuntimeException('sink broken');
            }
        };

        $logger = new MutableLogger([$failingSink], LogLevel::Debug, debug: false);

        // Should not throw
        $logger->error('test message');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function sinkExceptionWritesToStderrInDebugMode(): void
    {
        $failingSink = new class implements LogSinkInterface {
            public function write(LogEntry $entry): void
            {
                throw new RuntimeException('sink broken');
            }
        };

        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stderr);

        $logger = new MutableLogger([$failingSink], LogLevel::Debug, debug: true, stderr: $stderr);

        $logger->error('test message');

        rewind($stderr);
        $output = stream_get_contents($stderr);
        self::assertIsString($output);
        self::assertStringContainsString('sink broken', $output);

        fclose($stderr);
    }

    #[Test]
    public function channelIsPassedToLogEntry(): void
    {
        $written = [];
        $sink = $this->createSink($written);

        $logger = new MutableLogger([$sink], LogLevel::Debug, channel: 'custom-channel');

        $logger->info('test');

        self::assertSame('custom-channel', $written[0]->channel);
    }

    #[Test]
    public function contextIsPassedToLogEntry(): void
    {
        $written = [];
        $sink = $this->createSink($written);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->info('test', ['user_id' => 42, 'action' => 'login']);

        self::assertSame(42, $written[0]->context['user_id']);
        self::assertSame('login', $written[0]->context['action']);
    }

    #[Test]
    public function multipleSinksReceiveEntries(): void
    {
        $written1 = [];
        $written2 = [];
        $sink1 = $this->createSink($written1);
        $sink2 = $this->createSink($written2);

        $logger = new MutableLogger([$sink1, $sink2], LogLevel::Debug);

        $logger->info('broadcast');

        self::assertCount(1, $written1);
        self::assertCount(1, $written2);
    }

    #[Test]
    public function messageBelowThresholdIsDiscarded(): void
    {
        $written = [];
        $sink = $this->createSink($written);

        $logger = new MutableLogger([$sink], LogLevel::Error);

        $logger->debug('should not be logged');
        $logger->info('should not be logged');
        $logger->notice('should not be logged');
        $logger->warning('should not be logged');

        self::assertCount(0, $written);
    }

    #[Test]
    public function runtimeLevelChangeAffectsSubsequentMessages(): void
    {
        $written = [];
        $sink = $this->createSink($written);

        $logger = new MutableLogger([$sink], LogLevel::Error);

        $logger->info('ignored');
        self::assertCount(0, $written);

        $logger->setMinLevel(LogLevel::Debug);

        $logger->info('now logged');
        self::assertCount(1, $written);
    }

    /**
     * @param list<LogEntry> $storage
     */
    private function createSink(array &$storage): LogSinkInterface
    {
        return new class ($storage) implements LogSinkInterface {
            /** @param list<LogEntry> $storage */
            public function __construct(public array &$storage) {}

            public function write(LogEntry $entry): void
            {
                $this->storage[] = $entry;
            }
        };
    }
}
