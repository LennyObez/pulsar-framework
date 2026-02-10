<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Scheduler;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Internal\Service\AggregationService;

/**
 * Hourly job: aggregates raw page views into stats tables.
 *
 * At hour 0-1, also runs daily aggregation for the previous day.
 */
#[Internal(reason: 'Scheduled aggregation job')]
final readonly class AggregationJob
{
    public function __construct(
        private AggregationService $aggregationService,
        private SiteRepositoryInterface $siteRepository,
    ) {}

    public function __invoke(): void
    {
        $now = new DateTimeImmutable();
        $previousHour = $now->modify('-1 hour');
        $sites = $this->siteRepository->findAll();

        foreach ($sites as $site) {
            $this->aggregationService->aggregateHourly($previousHour, $site->id);

            // At the first hour of the day, also aggregate the previous day
            if ((int) $now->format('G') <= 1) {
                $yesterday = $now->modify('-1 day');
                $this->aggregationService->aggregateDaily($yesterday, $site->id);
            }
        }
    }
}
