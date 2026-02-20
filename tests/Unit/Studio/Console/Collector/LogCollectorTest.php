<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\LogCollector;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\LogEntryPayload;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use RuntimeException;

#[CoversClass(LogCollector::class)]
final class LogCollectorTest extends TestCase
{
    /** @var list<array{event: ConsoleEvent, context: ?CorrelationContext}> */
    private array $emittedEvents = [];

    protected function setUp(): void
    {
        $this->emittedEvents = [];
    }

    #[Test]
    public function writeEmitsLogEntryPayload(): void
    {
        $collector = $this->createCollector();
        $entry = $this->createLogEntry();

        $collector->write($entry);

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(LogEntryPayload::class, $this->emittedEvents[0]['event']);
    }

    #[Test]
    #[DataProvider('logLevelProvider')]
    public function writeRecordsLogLevel(LogLevel $level, string $expectedValue): void
    {
        $collector = $this->createCollector();
        $entry = $this->createLogEntry(level: $level);

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame($expectedValue, $payload->level);
    }

    /**
     * @return iterable<string, array{LogLevel, string}>
     */
    public static function logLevelProvider(): iterable
    {
        yield 'emergency' => [LogLevel::Emergency, 'emergency'];
        yield 'alert' => [LogLevel::Alert, 'alert'];
        yield 'critical' => [LogLevel::Critical, 'critical'];
        yield 'error' => [LogLevel::Error, 'error'];
        yield 'warning' => [LogLevel::Warning, 'warning'];
        yield 'notice' => [LogLevel::Notice, 'notice'];
        yield 'info' => [LogLevel::Info, 'info'];
        yield 'debug' => [LogLevel::Debug, 'debug'];
    }

    #[Test]
    public function writeRecordsMessage(): void
    {
        $collector = $this->createCollector();
        $entry = $this->createLogEntry(message: 'User logged in successfully');

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('User logged in successfully', $payload->message);
    }

    #[Test]
    public function writeRecordsChannel(): void
    {
        $collector = $this->createCollector();
        $entry = $this->createLogEntry(channel: 'security');

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('security', $payload->channel);
    }

    #[Test]
    public function writeRecordsContext(): void
    {
        $collector = $this->createCollector();
        $context = ['user_id' => 123, 'action' => 'login', 'ip' => '192.168.1.1'];
        $entry = $this->createLogEntry(context: $context);

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame($context, $payload->context);
    }

    #[Test]
    public function writeRecordsEmptyContext(): void
    {
        $collector = $this->createCollector();
        $entry = $this->createLogEntry(context: []);

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame([], $payload->context);
    }

    #[Test]
    public function writeRecordsNestedContext(): void
    {
        $collector = $this->createCollector();
        $context = [
            'request' => [
                'method' => 'POST',
                'path' => '/api/users',
            ],
            'user' => [
                'id' => 123,
                'roles' => ['admin', 'editor'],
            ],
        ];
        $entry = $this->createLogEntry(context: $context);

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame($context, $payload->context);
    }

    #[Test]
    public function writeUsesCurrentCorrelationContext(): void
    {
        $context = new CorrelationContext(
            requestId: 'req-abc',
            traceId: 'trace-xyz',
            spanId: 'span-123',
            jobId: 'job-456',
        );
        $contextProvider = $this->createContextProvider($context);
        $collector = $this->createCollector($contextProvider);
        $entry = $this->createLogEntry();

        $collector->write($entry);

        self::assertSame($context, $this->emittedEvents[0]['context']);
    }

    #[Test]
    public function writeHandlesNullCorrelationContext(): void
    {
        $contextProvider = $this->createContextProvider(null);
        $collector = $this->createCollector($contextProvider);
        $entry = $this->createLogEntry();

        $collector->write($entry);

        self::assertNull($this->emittedEvents[0]['context']);
    }

    #[Test]
    public function writeSkipsEmissionWhenDisabled(): void
    {
        $collector = $this->createCollector();
        $collector->enabled = false;
        $entry = $this->createLogEntry();

        $collector->write($entry);

        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function isEnabledReturnsTrueByDefault(): void
    {
        $collector = $this->createCollector();

        self::assertTrue($collector->enabled);
    }

    #[Test]
    public function setEnabledChangesEnabledState(): void
    {
        $collector = $this->createCollector();

        $collector->enabled = false;
        self::assertFalse($collector->enabled);

        $collector->enabled = true;
        self::assertTrue($collector->enabled);
    }

    #[Test]
    public function writeSilentlySwallowsEmitExceptions(): void
    {
        $contextProvider = $this->createContextProvider(null);
        $emitAttempts = 0;
        $collector = new LogCollector(
            contextProvider: $contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context) use (&$emitAttempts): void {
                $emitAttempts++;
                throw new RuntimeException('Emit failed');
            },
        );
        $entry = $this->createLogEntry();

        $collector->write($entry);

        // Emit was attempted (not skipped), and the exception was swallowed silently
        self::assertSame(1, $emitAttempts);
    }

    #[Test]
    public function writeHandlesEmptyMessage(): void
    {
        $collector = $this->createCollector();
        $entry = $this->createLogEntry(message: '');

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('', $payload->message);
    }

    #[Test]
    public function writeHandlesMultilineMessage(): void
    {
        $collector = $this->createCollector();
        $message = "First line\nSecond line\nThird line";
        $entry = $this->createLogEntry(message: $message);

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame($message, $payload->message);
    }

    #[Test]
    public function writeHandlesUnicodeMessage(): void
    {
        $collector = $this->createCollector();
        $message = 'User created with name: Uzu Maki';
        $entry = $this->createLogEntry(message: $message);

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame($message, $payload->message);
    }

    #[Test]
    public function writeHandlesSpecialCharactersInChannel(): void
    {
        $collector = $this->createCollector();
        $entry = $this->createLogEntry(channel: 'app.service.handler');

        $collector->write($entry);

        /** @var LogEntryPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('app.service.handler', $payload->channel);
    }

    #[Test]
    public function multipleWritesEmitMultipleEvents(): void
    {
        $collector = $this->createCollector();

        $collector->write($this->createLogEntry(message: 'First'));
        $collector->write($this->createLogEntry(message: 'Second'));
        $collector->write($this->createLogEntry(message: 'Third'));

        self::assertCount(3, $this->emittedEvents);

        /** @var LogEntryPayload $first */
        $first = $this->emittedEvents[0]['event'];
        /** @var LogEntryPayload $second */
        $second = $this->emittedEvents[1]['event'];
        /** @var LogEntryPayload $third */
        $third = $this->emittedEvents[2]['event'];

        self::assertSame('First', $first->message);
        self::assertSame('Second', $second->message);
        self::assertSame('Third', $third->message);
    }

    private function createCollector(
        ?CorrelationContextProviderInterface $contextProvider = null,
    ): LogCollector {
        return new LogCollector(
            contextProvider: $contextProvider ?? $this->createContextProvider(null),
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
    }

    private function createContextProvider(?CorrelationContext $context): CorrelationContextProviderInterface
    {
        return new class ($context) implements CorrelationContextProviderInterface {
            public function __construct(
                private readonly ?CorrelationContext $context,
            ) {}

            public function current(): ?CorrelationContext
            {
                return $this->context;
            }
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createLogEntry(
        LogLevel $level = LogLevel::Info,
        string $message = 'Test log message',
        array $context = [],
        string $channel = 'app',
    ): LogEntry {
        return new LogEntry(
            level: $level,
            message: $message,
            context: $context,
            channel: $channel,
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
