<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(LogEntry::class)]
final class LogEntryTest extends TestCase
{
    #[Test]
    public function createsWithUtcTimestamp(): void
    {
        $entry = LogEntry::create(LogLevel::Info, 'test message');

        self::assertSame('UTC', $entry->timestamp->getTimezone()->getName());
    }

    #[Test]
    public function preservesLevelAndMessage(): void
    {
        $entry = LogEntry::create(LogLevel::Error, 'Something failed');

        self::assertSame(LogLevel::Error, $entry->level);
        self::assertSame('Something failed', $entry->message);
    }

    #[Test]
    public function preservesContext(): void
    {
        $context = ['key' => 'value', 'count' => 42];
        $entry = LogEntry::create(LogLevel::Debug, 'test', $context);

        self::assertSame($context, $entry->context);
    }

    #[Test]
    public function preservesChannel(): void
    {
        $entry = LogEntry::create(LogLevel::Info, 'test', channel: 'custom');

        self::assertSame('custom', $entry->channel);
    }

    #[Test]
    public function defaultsToAppChannel(): void
    {
        $entry = LogEntry::create(LogLevel::Info, 'test');

        self::assertSame('app', $entry->channel);
    }

    #[Test]
    public function timestampIsRecentUtc(): void
    {
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $entry = LogEntry::create(LogLevel::Info, 'test');
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertGreaterThanOrEqual($before, $entry->timestamp);
        self::assertLessThanOrEqual($after, $entry->timestamp);
    }
}
