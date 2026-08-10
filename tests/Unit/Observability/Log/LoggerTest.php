<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AuditConfig;
use Pulsar\Config\LoggingChannelConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Filesystem\Exception\UnsafeWritablePathException;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use RuntimeException;

#[CoversClass(Logger::class)]
final class LoggerTest extends TestCase
{
    /** @var list<string> */
    private array $fallbackBuffer = [];

    /** @var Closure(string):void */
    private Closure $fallbackEmitter;

    /**
     * The logger invokes a last-resort fallback emitter on sink
     * failures (PHP `error_log` by default). PHPUnit treats any
     * unexpected output as a Risky-test signal, so the test suite
     * substitutes the fallback with an in-memory buffer for every
     * test — individual tests that need to assert on the content
     * read `$this->fallbackBuffer`.
     */
    protected function setUp(): void
    {
        $this->fallbackBuffer = [];
        $this->fallbackEmitter = function (string $message): void {
            $this->fallbackBuffer[] = $message;
        };
    }

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
        $logger = new Logger(
            sinks: [$failingSink, $collectingSink],
            threshold: LogLevel::Debug,
            fallbackEmitter: $this->fallbackEmitter,
        );

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
            audit: new AuditConfig(
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
    public function fromConfigRefusesAFileChannelPathInsideTheDocumentRoot(): void
    {
        // A file log channel whose path resolves under public/ would put
        // PII/PHI-bearing logs one guessed URL away. The channel must fail
        // closed at construction, exactly like the cache/session/audit sinks.
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_logguard_' . bin2hex(random_bytes(8));
        mkdir($base . DIRECTORY_SEPARATOR . 'public', 0o750, true);
        $previous = getenv('PULSAR_BASE_PATH');
        putenv('PULSAR_BASE_PATH=' . $base);

        $config = new ObservabilityConfig(
            defaultLoggingChannel: 'file',
            loggingLevel: 'warning',
            loggingChannels: [
                new LoggingChannelConfig(
                    name: 'file',
                    driver: 'file',
                    path: 'public/logs/app.log',
                ),
            ],
            audit: new AuditConfig(enabled: false, logPath: 'var/logs/audit.jsonl', events: []),
        );

        try {
            $this->expectException(UnsafeWritablePathException::class);
            (void) Logger::fromConfig($config);
        } finally {
            if ($previous === false) {
                putenv('PULSAR_BASE_PATH');
            } else {
                putenv('PULSAR_BASE_PATH=' . $previous);
            }
            @rmdir($base . DIRECTORY_SEPARATOR . 'public');
            @rmdir($base);
        }
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
            audit: new AuditConfig(
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
            audit: new AuditConfig(
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

    #[Test]
    public function sinkFailureWritesToStderrInDebugMode(): void
    {
        $stderr = fopen('php://temp', 'r+b');
        self::assertIsResource($stderr);

        $failingSink = new FailingSink();
        $collectingSink = new CollectingSink();
        $logger = new Logger(
            sinks: [$failingSink, $collectingSink],
            threshold: LogLevel::Debug,
            debug: true,
            stderr: $stderr,
            fallbackEmitter: $this->fallbackEmitter,
        );

        $logger->error('test');

        rewind($stderr);
        $output = stream_get_contents($stderr);
        fclose($stderr);

        self::assertIsString($output);
        self::assertStringContainsString('[Pulsar Logger] Sink failure: Sink failure', $output);

        // Second sink still received the entry
        self::assertCount(1, $collectingSink->entries);
    }

    #[Test]
    public function sinkFailureDoesNotWriteToStderrWhenNotDebug(): void
    {
        $stderr = fopen('php://temp', 'r+b');
        self::assertIsResource($stderr);

        $failingSink = new FailingSink();
        $logger = new Logger(
            sinks: [$failingSink],
            threshold: LogLevel::Debug,
            debug: false,
            stderr: $stderr,
            fallbackEmitter: $this->fallbackEmitter,
        );

        $logger->error('test');

        rewind($stderr);
        $output = stream_get_contents($stderr);
        fclose($stderr);

        self::assertSame('', $output);
    }

    /**
     * When every sink fails, the entry must still reach the
     * fallback emitter so it is not dropped silently.
     */
    #[Test]
    public function fallsBackToErrorLogWhenAllSinksFail(): void
    {
        $logger = new Logger(
            sinks: [new FailingSink(), new FailingSink()],
            threshold: LogLevel::Debug,
            fallbackEmitter: $this->fallbackEmitter,
        );

        $logger->error('chain dropped — must surface');

        $combined = implode("\n", $this->fallbackBuffer);
        self::assertStringContainsString('Pulsar Logger fallback', $combined);
        self::assertStringContainsString('chain dropped — must surface', $combined);
        // Sink failure announce line is also emitted (twice, once per
        // failing sink).
        self::assertStringContainsString('sink failure', strtolower($combined));
    }

    /**
     * When at least one sink succeeds, the fallback path is NOT
     * triggered — the emitter is for the all-failed scenario, not a
     * nice-to-have duplicate write.
     */
    #[Test]
    public function noFallbackWhenAtLeastOneSinkSucceeds(): void
    {
        $logger = new Logger(
            sinks: [new FailingSink(), new CollectingSink()],
            threshold: LogLevel::Debug,
            fallbackEmitter: $this->fallbackEmitter,
        );

        $logger->error('partial failure — second sink absorbs');

        // The sink-failure announce still fires (operators need to
        // know the channel is broken), but the fallback content
        // does not — the entry is already preserved by the
        // surviving sink.
        $combined = implode("\n", $this->fallbackBuffer);
        self::assertStringNotContainsString('Pulsar Logger fallback', $combined);
        // The announce-failure path still fires.
        self::assertStringContainsString('sink failure', strtolower($combined));
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
