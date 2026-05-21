<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Scheduler;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Internal\Repository\DbHourlyStatsRepository;

use function sprintf;

/**
 * Daily cleanup job: purges analytics data beyond configured retention periods.
 *
 * Operates in batches to avoid long table locks.
 */
#[Internal(reason: 'Scheduled retention cleanup job')]
final readonly class RetentionCleanupJob
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private AnalyticsConfig $config,
        private PageViewRepositoryInterface $pageViewRepository,
        private EventRepositoryInterface $eventRepository,
        private SessionRepositoryInterface $sessionRepository,
        private DailyStatsRepositoryInterface $dailyStatsRepository,
        private DbHourlyStatsRepository $hourlyStatsRepository,
    ) {}

    public function __invoke(): void
    {
        $now = new DateTimeImmutable();

        // Raw data retention
        $rawCutoff = $now->modify(sprintf('-%d days', $this->config->retention->rawDays));
        $this->pageViewRepository->deleteOlderThan($rawCutoff);
        $this->eventRepository->deleteOlderThan($rawCutoff);
        $this->sessionRepository->deleteOlderThan($rawCutoff);

        // Hourly stats retention
        $hourlyCutoff = $now->modify(sprintf('-%d hours', $this->config->retention->hourlyHours));
        $this->hourlyStatsRepository->deleteOlderThan($hourlyCutoff);

        // Aggregated stats retention
        $aggregatedCutoff = $now->modify(sprintf('-%d days', $this->config->retention->aggregatedDays));
        $this->dailyStatsRepository->deleteOlderThan($aggregatedCutoff);
    }
}
