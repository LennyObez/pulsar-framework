<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Observability\Log\Sink\DeferredSink;

#[CoversClass(DeferredSink::class)]
final class DeferredSinkTest extends TestCase
{
    #[Test]
    public function writeIsNoOpWhenNoSinksAttached(): void
    {
        $sink = new DeferredSink();
        $entry = LogEntry::create(LogLevel::Info, 'test message');

        // Should not throw or buffer — pure no-op
        $sink->write($entry);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function lateSinkAttachReceivesSubsequentWrites(): void
    {
        $deferred = new DeferredSink();
        $entry1 = LogEntry::create(LogLevel::Info, 'before attach');
        $entry2 = LogEntry::create(LogLevel::Warning, 'after attach');

        // Write before any sink attached — silently dropped
        $deferred->write($entry1);

        $received = [];
        $spy = new class ($received) implements LogSinkInterface {
            /** @param list<LogEntry> $received */
            public function __construct(private array &$received) {} // @phpstan-ignore property.onlyWritten

            public function write(LogEntry $entry): void
            {
                $this->received[] = $entry;
            }
        };

        $deferred->addSink($spy);

        // Write after attach — reaches the sink
        $deferred->write($entry2);

        self::assertCount(1, $received);
        self::assertSame('after attach', $received[0]->message);
    }
}
