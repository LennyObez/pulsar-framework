<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Noop;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Noop\NoopLogSink;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;

#[CoversClass(NoopLogSink::class)]
final class NoopLogSinkTest extends TestCase
{
    #[Test]
    public function implementsLogSinkInterface(): void
    {
        $sink = new NoopLogSink();

        self::assertInstanceOf(LogSinkInterface::class, $sink);
    }

    #[Test]
    public function writeDoesNotThrow(): void
    {
        $sink = new NoopLogSink();
        $entry = new LogEntry(
            level: LogLevel::Error,
            message: 'test error',
            context: ['key' => 'value'],
            channel: 'app',
            timestamp: new DateTimeImmutable(),
        );

        // Verify it completes without exception
        $sink->write($entry);

        // If we got here, the noop sink correctly discarded the entry
        self::assertInstanceOf(NoopLogSink::class, $sink);
    }
}
