<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Observability\Log\MutableLogger;
use RuntimeException;

#[CoversClass(MutableLogger::class)]
final class MutableLoggerTest extends TestCase
{
    #[Test]
    public function logWritesToAllSinks(): void
    {
        $entries1 = [];
        $entries2 = [];
        $sink1 = $this->createSinkCapturing($entries1);
        $sink2 = $this->createSinkCapturing($entries2);

        $logger = new MutableLogger([$sink1, $sink2], LogLevel::Debug);

        $logger->error('Something went wrong');

        self::assertCount(1, $entries1);
        self::assertCount(1, $entries2);
        self::assertSame('Something went wrong', $entries1[0]->message);
    }

    #[Test]
    public function messagesBelowThresholdAreDiscarded(): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Warning);

        $logger->info('Should be discarded');
        $logger->debug('Also discarded');

        self::assertCount(0, $entries);
    }

    #[Test]
    public function messagesAtOrAboveThresholdAreWritten(): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Warning);

        $logger->warning('At threshold');
        $logger->error('Above threshold');
        $logger->critical('Well above');

        self::assertCount(3, $entries);
    }

    #[Test]
    public function setMinLevelChangesThresholdAtRuntime(): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Error);

        $logger->warning('Before change — discarded');
        self::assertCount(0, $entries);

        $logger->setMinLevel(LogLevel::Debug);

        $logger->debug('After change — written');
        self::assertCount(1, $entries);
    }

    #[Test]
    public function minLevelReturnsCurrentThreshold(): void
    {
        $logger = new MutableLogger([], LogLevel::Warning);

        self::assertSame(LogLevel::Warning, $logger->minLevel());

        $logger->setMinLevel(LogLevel::Debug);

        self::assertSame(LogLevel::Debug, $logger->minLevel());
    }

    #[Test]
    #[DataProvider('psrMethodProvider')]
    public function psrConvenienceMethodsLogAtCorrectLevel(string $method, LogLevel $expectedLevel): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->$method('test message');

        self::assertCount(1, $entries);
        self::assertSame($expectedLevel, $entries[0]->level);
    }

    /**
     * @return iterable<string, array{string, LogLevel}>
     */
    public static function psrMethodProvider(): iterable
    {
        yield 'emergency' => ['emergency', LogLevel::Emergency];
        yield 'alert' => ['alert', LogLevel::Alert];
        yield 'critical' => ['critical', LogLevel::Critical];
        yield 'error' => ['error', LogLevel::Error];
        yield 'warning' => ['warning', LogLevel::Warning];
        yield 'notice' => ['notice', LogLevel::Notice];
        yield 'info' => ['info', LogLevel::Info];
        yield 'debug' => ['debug', LogLevel::Debug];
    }

    #[Test]
    public function logWithStringLevelWorksViaPsrInterface(): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->log('error', 'String level test');

        self::assertCount(1, $entries);
        self::assertSame(LogLevel::Error, $entries[0]->level);
    }

    #[Test]
    public function logWithUnknownStringLevelDefaultsToDebug(): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->log('nonexistent', 'Fallback test');

        self::assertCount(1, $entries);
        self::assertSame(LogLevel::Debug, $entries[0]->level);
    }

    #[Test]
    public function contextIsPassedToLogEntry(): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->error('Error with context', ['user_id' => 42, 'action' => 'login']);

        self::assertSame(42, $entries[0]->context['user_id']);
        self::assertSame('login', $entries[0]->context['action']);
    }

    #[Test]
    public function channelIsSetOnLogEntries(): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Debug, channel: 'security');

        $logger->warning('Suspicious activity');

        self::assertSame('security', $entries[0]->channel);
    }

    #[Test]
    public function sinkFailureIsSwallowedInNonDebugMode(): void
    {
        $failingSink = $this->createStub(LogSinkInterface::class);
        $failingSink->method('write')->willThrowException(new RuntimeException('Sink unavailable'));

        $entries = [];
        $goodSink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$failingSink, $goodSink], LogLevel::Debug);

        $logger->error('Should not crash');

        self::assertCount(1, $entries);
    }

    #[Test]
    public function sinkFailureWritesToStderrInDebugMode(): void
    {
        $failingSink = $this->createStub(LogSinkInterface::class);
        $failingSink->method('write')->willThrowException(new RuntimeException('Connection lost'));

        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        // The single sink fails, so the all-sinks-failed durability fallback
        // also fires; capture it here to keep it off the real error_log.
        $fallbacks = [];
        $logger = new MutableLogger(
            [$failingSink],
            LogLevel::Debug,
            debug: true,
            stderr: $stream,
            fallbackEmitter: static function (string $message) use (&$fallbacks): void {
                $fallbacks[] = $message;
            },
        );

        $logger->error('Trigger sink failure');

        rewind($stream);
        /** @var string $output */
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('Sink failure', $output);
        self::assertStringContainsString('Connection lost', $output);
        self::assertCount(1, $fallbacks);
    }

    #[Test]
    public function fallbackEmitterReceivesEntryWhenAllSinksFail(): void
    {
        $failingSink = $this->createStub(LogSinkInterface::class);
        $failingSink->method('write')->willThrowException(new RuntimeException('disk full'));

        $fallbacks = [];
        $logger = new MutableLogger(
            [$failingSink],
            LogLevel::Debug,
            fallbackEmitter: static function (string $message) use (&$fallbacks): void {
                $fallbacks[] = $message;
            },
        );

        $logger->critical('audit-critical record');

        self::assertCount(1, $fallbacks);
        self::assertStringContainsString('[Pulsar Logger fallback]', $fallbacks[0]);
        self::assertStringContainsString('audit-critical record', $fallbacks[0]);
    }

    #[Test]
    public function fallbackEmitterFiresWhenNoSinksAreConfigured(): void
    {
        $fallbacks = [];
        $logger = new MutableLogger(
            [],
            LogLevel::Debug,
            fallbackEmitter: static function (string $message) use (&$fallbacks): void {
                $fallbacks[] = $message;
            },
        );

        $logger->error('lost without a sink');

        self::assertCount(1, $fallbacks);
        self::assertStringContainsString('lost without a sink', $fallbacks[0]);
    }

    #[Test]
    public function fallbackEmitterDoesNotFireWhenAnySinkSucceeds(): void
    {
        $failingSink = $this->createStub(LogSinkInterface::class);
        $failingSink->method('write')->willThrowException(new RuntimeException('flaky'));

        $entries = [];
        $goodSink = $this->createSinkCapturing($entries);

        $fallbacks = [];
        $logger = new MutableLogger(
            [$failingSink, $goodSink],
            LogLevel::Debug,
            fallbackEmitter: static function (string $message) use (&$fallbacks): void {
                $fallbacks[] = $message;
            },
        );

        $logger->error('survives via good sink');

        self::assertCount(1, $entries);
        self::assertCount(0, $fallbacks);
    }

    #[Test]
    public function logWithLogLevelEnumInstanceWorksDirectly(): void
    {
        $entries = [];
        $sink = $this->createSinkCapturing($entries);

        $logger = new MutableLogger([$sink], LogLevel::Debug);

        $logger->log(LogLevel::Critical, 'Direct enum level');

        self::assertCount(1, $entries);
        self::assertSame(LogLevel::Critical, $entries[0]->level);
    }

    /**
     * @param list<LogEntry> $captured
     */
    private function createSinkCapturing(array &$captured): LogSinkInterface
    {
        return new class ($captured) implements LogSinkInterface {
            /**
             * @param list<LogEntry> $captured
             */
            public function __construct(private array &$captured) {}

            public function write(LogEntry $entry): void
            {
                $this->captured[] = $entry;
            }

            /**
             * @return list<LogEntry>
             */
            public function entries(): array
            {
                return $this->captured;
            }
        };
    }
}
