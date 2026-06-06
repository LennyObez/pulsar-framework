<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Scheduler;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\AggregationServiceInterface;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;

/**
 * Hourly job: aggregates raw page views into stats tables.
 *
 * Runs hourly aggregation for the previous hour, and checks whether
 * the previous day's daily aggregation is missing or stale, running
 * it if needed regardless of the current hour.
 */
#[Internal(reason: 'Scheduled aggregation job')]
final readonly class AggregationJob
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private AggregationServiceInterface $aggregationService,
        private SiteRepositoryInterface $siteRepository,
        private DailyStatsRepositoryInterface $dailyStatsRepository,
    ) {}

    public function __invoke(): void
    {
        $now = new DateTimeImmutable();
        $previousHour = $now->modify('-1 hour');
        $yesterday = $now->modify('-1 day');
        $sites = $this->siteRepository->findAll();

        foreach ($sites as $site) {
            $this->aggregationService->aggregateHourly($previousHour, $site->id);

            // Always check if yesterday's daily stats exist, run if missing
            if ($this->isDailyAggregationMissing($site->id, $yesterday)) {
                $this->aggregationService->aggregateDaily($yesterday, $site->id);
            }
        }
    }

    /**
     * Check if the daily aggregation for the given date is missing.
     */
    private function isDailyAggregationMissing(string $siteId, DateTimeImmutable $date): bool
    {
        $stats = $this->dailyStatsRepository->findByDateRange(
            $siteId,
            $date,
            $date,
        );

        return $stats === [];
    }
}
