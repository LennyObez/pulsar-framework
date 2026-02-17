<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Analytics\Internal\Scheduler\PartitionMaintenanceJob;
use RuntimeException;

final class PartitionMaintenanceJobTest extends TestCase
{
    #[Test]
    public function noop_for_sqlite(): void
    {
        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->expects(self::never())->method('execute');

        $job = new PartitionMaintenanceJob($connection);
        $job();
    }

    #[Test]
    public function creates_partitions_for_mysql(): void
    {
        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        // 2 tables * 4 weeks = 8 ALTER TABLE calls
        $connection->expects(self::exactly(8))->method('execute');

        $job = new PartitionMaintenanceJob($connection);
        $job();
    }

    #[Test]
    public function silently_handles_duplicate_partitions(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('execute')->willThrowException(new RuntimeException('Partition already exists'));

        // Should not throw even when partitions already exist
        $job = new PartitionMaintenanceJob($connection);
        $job();

        // If we reach here without exception, the job silently caught the error
        self::assertTrue(true, 'Job completed without propagating partition duplicate error');
    }
}
