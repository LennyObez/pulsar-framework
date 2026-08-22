<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
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

use function date_create_immutable;

#[CoversClass(LogCollector::class)]
final class LogCollectorTest extends TestCase
{
    #[Test]
    public function writeEmitsLogEntryPayload(): void
    {
        $emitted = null;
        $contextProvider = $this->createStub(CorrelationContextProviderInterface::class);
        $contextProvider->method('current')->willReturn(null);

        $collector = new LogCollector(
            $contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
                $emitted = $event;
            },
        );

        $entry = new LogEntry(
            level: LogLevel::Error,
            message: 'Database connection lost',
            context: ['host' => 'localhost'],
            channel: 'db',
            timestamp: date_create_immutable('2026-01-01'),
        );

        $collector->write($entry);

        self::assertInstanceOf(LogEntryPayload::class, $emitted);
        self::assertSame('error', $emitted->level);
        self::assertSame('Database connection lost', $emitted->message);
        self::assertSame('db', $emitted->channel);
    }

    #[Test]
    public function writeDoesNothingWhenDisabled(): void
    {
        $emitted = false;
        $contextProvider = $this->createStub(CorrelationContextProviderInterface::class);

        $collector = new LogCollector(
            $contextProvider,
            function () use (&$emitted): void {
                $emitted = true;
            },
        );
        $collector->enabled = false;

        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'test',
            context: [],
            channel: 'app',
            timestamp: date_create_immutable('2026-01-01'),
        );

        $collector->write($entry);

        self::assertFalse($emitted);
    }

    #[Test]
    public function writeSilentlySwallowsEmitFailures(): void
    {
        $contextProvider = $this->createStub(CorrelationContextProviderInterface::class);
        $contextProvider->method('current')->willReturn(null);

        $collector = new LogCollector(
            $contextProvider,
            function (): never {
                throw new RuntimeException('emit failed');
            },
        );

        $entry = new LogEntry(
            level: LogLevel::Warning,
            message: 'test',
            context: [],
            channel: 'app',
            timestamp: date_create_immutable('2026-01-01'),
        );

        $collector->write($entry);

        self::assertTrue(true, 'Emit failure was silently handled');
    }
}
