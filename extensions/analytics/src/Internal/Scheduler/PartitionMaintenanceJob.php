<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Scheduler;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Throwable;

use function sprintf;

/**
 * Weekly job: creates future partitions and drops expired ones.
 *
 * Only operates on MySQL/MariaDB: no-op for SQLite.
 */
#[Internal(reason: 'Scheduled partition maintenance job')]
final readonly class PartitionMaintenanceJob
{
    private const array PARTITIONED_TABLES = [
        'analytics_page_views',
        'analytics_events',
    ];
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function __invoke(): void
    {
        if ($this->connection->driver() !== Driver::MySQL) {
            return;
        }

        $now = new DateTimeImmutable();

        foreach (self::PARTITIONED_TABLES as $table) {
            // Create partitions for the next 4 weeks
            for ($i = 1; $i <= 4; $i++) {
                $futureWeek = $now->modify("+$i week");
                $yearWeek = (int) $futureWeek->format('oW');
                $partitionName = sprintf('p%d', $yearWeek);

                $nextWeek = $futureWeek->modify('+1 week');
                $nextYearWeek = (int) $nextWeek->format('oW');

                $sql = sprintf(
                    'ALTER TABLE %s ADD PARTITION (PARTITION %s VALUES LESS THAN (%d))',
                    $table,
                    $partitionName,
                    $nextYearWeek,
                );

                try {
                    $this->connection->execute($sql);
                } catch (Throwable) {
                    // Partition may already exist
                }
            }
        }
    }
}
