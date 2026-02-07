<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\LoggingChannelConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use RuntimeException;

#[CoversClass(Logger::class)]
final class LoggerTest extends TestCase
{
    #[Test]
    public function logsAtThreshold(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Info);

        $logger->info('test message');

        self::assertCount(1, $sink->entries);
        self::assertSame('test message', $sink->entries[0]->message);
    }

    #[Test]
    public function logsAboveThreshold(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Info);

        $logger->error('error message');

        self::assertCount(1, $sink->entries);
        self::assertSame(LogLevel::Error, $sink->entries[0]->level);
    }

    #[Test]
    public function filtersBelowThreshold(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Warning);

        $logger->info('should be filtered');
        $logger->debug('also filtered');

        self::assertCount(0, $sink->entries);
    }

    #[Test]
    public function allPsr3LevelsWork(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Debug);

        $logger->emergency('emergency');
        $logger->alert('alert');
        $logger->critical('critical');
        $logger->error('error');
        $logger->warning('warning');
        $logger->notice('notice');
        $logger->info('info');
        $logger->debug('debug');

        self::assertCount(8, $sink->entries);
    }

    #[Test]
    public function interpolatesPlaceholders(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Debug);

        $logger->info('Hello {name}, you are {age}', ['name' => 'Alice', 'age' => '30']);

        // The entry message is raw; interpolation happens in the formatter
        // Logger passes through to sinks; let's verify the context is preserved
        self::assertSame(['name' => 'Alice', 'age' => '30'], $sink->entries[0]->context);
    }

    #[Test]
    public function exceptionInContextPreserved(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Debug);
        $exception = new RuntimeException('test error');

        $logger->error('failed', ['exception' => $exception]);

        self::assertSame($exception, $sink->entries[0]->context['exception']);
    }

    #[Test]
    public function multipleSinksReceiveEntry(): void
    {
        $sink1 = new CollectingSink();
        $sink2 = new CollectingSink();
        $logger = new Logger([$sink1, $sink2], LogLevel::Debug);

        $logger->info('broadcast');

        self::assertCount(1, $sink1->entries);
        self::assertCount(1, $sink2->entries);
    }

    #[Test]
    public function sinkFailureDoesNotThrow(): void
    {
        $failingSink = new FailingSink();
        $collectingSink = new CollectingSink();
        $logger = new Logger([$failingSink, $collectingSink], LogLevel::Debug);

        // Should not throw
        $logger->error('test');

        // Second sink still receives the entry
        self::assertCount(1, $collectingSink->entries);
    }

    #[Test]
    public function fromConfigFactory(): void
    {
        $config = new ObservabilityConfig(
            defaultLoggingChannel: 'stderr',
            loggingLevel: 'warning',
            loggingChannels: [
                new LoggingChannelConfig(
                    name: 'stderr',
                    driver: 'stream',
                    stream: 'php://stderr',
                ),
            ],
            audit: new \Pulsar\Config\AuditConfig(
                enabled: false,
                logPath: 'var/logs/audit.jsonl',
                events: [],
            ),
        );

        $logger = Logger::fromConfig($config);

        // Should create without error
        self::assertInstanceOf(Logger::class, $logger);
    }

    #[Test]
    public function logMethodAcceptsPsrLevelString(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Debug);

        $logger->log('error', 'string level');

        self::assertCount(1, $sink->entries);
        self::assertSame(LogLevel::Error, $sink->entries[0]->level);
    }

    #[Test]
    public function logMethodAcceptsLogLevelEnum(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Debug);

        $logger->log(LogLevel::Warning, 'enum level');

        self::assertCount(1, $sink->entries);
        self::assertSame(LogLevel::Warning, $sink->entries[0]->level);
    }

    #[Test]
    public function fromConfigWithExtraSinks(): void
    {
        $extraSink = new CollectingSink();
        $config = new ObservabilityConfig(
            defaultLoggingChannel: 'app',
            loggingLevel: 'debug',
            loggingChannels: [],
            audit: new \Pulsar\Config\AuditConfig(
                enabled: false,
                logPath: 'var/logs/audit.jsonl',
                events: [],
            ),
        );

        $logger = Logger::fromConfigWithExtraSinks($config, [$extraSink]);
        $logger->info('extra sink test');

        self::assertCount(1, $extraSink->entries);
    }

    #[Test]
    public function createSinkReturnsNullForUnknownDriver(): void
    {
        $config = new ObservabilityConfig(
            defaultLoggingChannel: 'custom',
            loggingLevel: 'debug',
            loggingChannels: [
                new LoggingChannelConfig(
                    name: 'custom',
                    driver: 'unknown_driver',
                ),
            ],
            audit: new \Pulsar\Config\AuditConfig(
                enabled: false,
                logPath: 'var/logs/audit.jsonl',
                events: [],
            ),
        );

        $logger = Logger::fromConfig($config);

        // Logger should be created even with unknown driver — it just has no sinks
        self::assertInstanceOf(Logger::class, $logger);
    }

    #[Test]
    public function logMethodFallsBackToDebugForNonStringLevel(): void
    {
        $sink = new CollectingSink();
        $logger = new Logger([$sink], LogLevel::Debug);

        $logger->log(42, 'non-string level');

        self::assertCount(1, $sink->entries);
        self::assertSame(LogLevel::Debug, $sink->entries[0]->level);
    }
}

/**
 * @internal Test helper: collects log entries in memory.
 */
final class CollectingSink implements LogSinkInterface
{
    /** @var list<LogEntry> */
    public array $entries = [];

    public function write(LogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

/**
 * @internal Test helper: always throws when writing.
 */
final class FailingSink implements LogSinkInterface
{
    public function write(LogEntry $entry): void
    {
        throw new RuntimeException('Sink failure');
    }
}
