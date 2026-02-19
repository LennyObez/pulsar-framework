<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Search\DateRange;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function sprintf;

/**
 * Scheduled job that computes daily search analytics summary.
 *
 * Executes daily at 4 AM UTC. Retrieves yesterday's search totals
 * and logs them as a summary for monitoring purposes.
 */
#[Internal(reason: 'CMS search analytics daily summary job')]
final readonly class SearchAnalyticsCleanupJob implements JobInterface
{
    public function __construct(
        private SearchAnalyticsRepositoryInterface $analyticsRepository,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'cms:search-analytics-summary';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::dailyAt('04:00');
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $yesterday = new DateTimeImmutable('yesterday 00:00:00');
            $endOfDay = new DateTimeImmutable('yesterday 23:59:59');
            $range = new DateRange($yesterday, $endOfDay);

            $totals = $this->analyticsRepository->getTotals($range, null);

            return JobResult::success(
                $this->getName(),
                $startedAt,
                sprintf(
                    'Search analytics summary: %d total searches, %d unique queries',
                    $totals['total_searches'],
                    $totals['unique_queries'],
                ),
            );
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Computes daily search analytics summary for monitoring';
    }
}
