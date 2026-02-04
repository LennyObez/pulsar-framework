<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Migration\MigrationRecord;

#[CoversClass(MigrationRecord::class)]
final class MigrationRecordTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $appliedAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $record = new MigrationRecord(
            version: '20240115103000',
            name: 'create_users_table',
            batch: 1,
            appliedAt: $appliedAt,
        );

        self::assertSame('20240115103000', $record->version);
        self::assertSame('create_users_table', $record->name);
        self::assertSame(1, $record->batch);
        self::assertSame($appliedAt, $record->appliedAt);
    }

    #[Test]
    public function fromArrayCreatesRecordFromDatabaseRow(): void
    {
        $record = MigrationRecord::fromArray([
            'version' => '20240115103000',
            'name' => 'create_users_table',
            'batch' => 2,
            'applied_at' => '2024-01-15 10:30:00',
        ]);

        self::assertSame('20240115103000', $record->version);
        self::assertSame('create_users_table', $record->name);
        self::assertSame(2, $record->batch);
        self::assertSame('2024-01-15 10:30:00', $record->appliedAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function fromArrayHandlesDefaults(): void
    {
        $record = MigrationRecord::fromArray([]);

        self::assertSame('', $record->version);
        self::assertSame('', $record->name);
        self::assertSame(0, $record->batch);
    }
}
