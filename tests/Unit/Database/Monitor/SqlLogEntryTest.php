<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Monitor\QueryClassification;
use Pulsar\Database\Monitor\SqlLogEntry;

#[CoversClass(SqlLogEntry::class)]
final class SqlLogEntryTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $entry = new SqlLogEntry(
            normalizedSql: 'SELECT * FROM users WHERE id = ?',
            bindingHash: 'abc123',
            durationMs: 5.5,
            rowCount: 3,
            classification: QueryClassification::Select,
            sensitivityLevel: 'standard',
        );

        self::assertSame('SELECT * FROM users WHERE id = ?', $entry->normalizedSql);
        self::assertSame('abc123', $entry->bindingHash);
        self::assertSame(5.5, $entry->durationMs);
        self::assertSame(3, $entry->rowCount);
        self::assertSame(QueryClassification::Select, $entry->classification);
        self::assertSame('standard', $entry->sensitivityLevel);
    }
}
