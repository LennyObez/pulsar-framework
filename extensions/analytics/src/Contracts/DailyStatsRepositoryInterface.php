<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\DailyStats;

/**
 * Persistence interface for pre-aggregated daily statistics.
 */
#[Api(since: '1.0.0')]
interface DailyStatsRepositoryInterface
{
    /**
     * Insert or update daily stats (upsert).
     */
    public function upsert(DailyStats $stats): void;

    /**
     * @return list<DailyStats>
     */
    public function findByDateRange(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Delete aggregated stats older than the given date.
     *
     * @return int Number of deleted rows
     */
    public function deleteOlderThan(DateTimeImmutable $before): int;
}
