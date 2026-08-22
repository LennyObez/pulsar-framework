<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\QueryLog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\QueryLog\QueryLogEntry;
use Pulsar\Database\QueryLog\QueryLogger;

#[CoversClass(QueryLogger::class)]
#[CoversClass(QueryLogEntry::class)]
final class QueryLoggerTest extends TestCase
{
    #[Test]
    public function starts_disabled(): void
    {
        $logger = new QueryLogger();

        self::assertFalse($logger->isEnabled());
    }

    #[Test]
    public function enable_and_disable_toggle_state(): void
    {
        $logger = new QueryLogger();

        $logger->enable();
        self::assertTrue($logger->isEnabled());

        $logger->disable();
        self::assertFalse($logger->isEnabled());
    }

    #[Test]
    public function log_is_ignored_when_disabled(): void
    {
        $logger = new QueryLogger();
        $logger->log('SELECT 1', [], 0.5);

        self::assertSame(0, $logger->count());
        self::assertSame([], $logger->entries());
    }

    #[Test]
    public function log_records_entry_when_enabled(): void
    {
        $logger = new QueryLogger();
        $logger->enable();

        $logger->log('SELECT * FROM users WHERE id = :id', ['id' => 42], 1.5);

        self::assertSame(1, $logger->count());

        $entry = $logger->entries()[0];
        self::assertSame('SELECT * FROM users WHERE id = :id', $entry->sql);
        self::assertSame(['id' => 42], $entry->bindings);
        self::assertSame(1.5, $entry->durationMs);
    }

    #[Test]
    public function total_time_sums_all_entries(): void
    {
        $logger = new QueryLogger();
        $logger->enable();

        $logger->log('SELECT 1', [], 1.0);
        $logger->log('SELECT 2', [], 2.5);
        $logger->log('SELECT 3', [], 0.5);

        self::assertSame(4.0, $logger->totalTimeMs());
    }

    #[Test]
    public function total_time_is_zero_for_empty_log(): void
    {
        $logger = new QueryLogger();

        self::assertSame(0.0, $logger->totalTimeMs());
    }

    #[Test]
    public function slowest_returns_entries_sorted_by_duration_descending(): void
    {
        $logger = new QueryLogger();
        $logger->enable();

        $logger->log('fast', [], 0.1);
        $logger->log('slow', [], 10.0);
        $logger->log('medium', [], 5.0);

        $slowest = $logger->slowest(2);

        self::assertCount(2, $slowest);
        self::assertSame('slow', $slowest[0]->sql);
        self::assertSame('medium', $slowest[1]->sql);
    }

    #[Test]
    public function slowest_returns_all_entries_when_limit_exceeds_count(): void
    {
        $logger = new QueryLogger();
        $logger->enable();

        $logger->log('q1', [], 1.0);
        $logger->log('q2', [], 2.0);

        $slowest = $logger->slowest(10);

        self::assertCount(2, $slowest);
    }

    #[Test]
    public function clear_removes_all_entries(): void
    {
        $logger = new QueryLogger();
        $logger->enable();

        $logger->log('q1', [], 1.0);
        $logger->log('q2', [], 2.0);

        self::assertSame(2, $logger->count());

        $logger->clear();

        self::assertSame(0, $logger->count());
        self::assertSame([], $logger->entries());
    }

    #[Test]
    public function entry_preserves_caller_info(): void
    {
        $entry = new QueryLogEntry(
            sql: 'SELECT 1',
            bindings: [],
            durationMs: 0.5,
            callerFile: '/app/src/User.php',
            callerLine: 42,
        );

        self::assertSame('/app/src/User.php', $entry->callerFile);
        self::assertSame(42, $entry->callerLine);
    }

    #[Test]
    public function entry_has_null_caller_by_default(): void
    {
        $entry = new QueryLogEntry(
            sql: 'SELECT 1',
            bindings: [],
            durationMs: 0.5,
        );

        self::assertNull($entry->callerFile);
        self::assertNull($entry->callerLine);
    }

    #[Test]
    public function multiple_logs_maintain_insertion_order(): void
    {
        $logger = new QueryLogger();
        $logger->enable();

        $logger->log('first', [], 1.0);
        $logger->log('second', [], 2.0);
        $logger->log('third', [], 3.0);

        $entries = $logger->entries();

        self::assertSame('first', $entries[0]->sql);
        self::assertSame('second', $entries[1]->sql);
        self::assertSame('third', $entries[2]->sql);
    }
}
