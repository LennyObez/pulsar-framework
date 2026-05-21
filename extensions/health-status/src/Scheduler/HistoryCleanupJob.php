<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Extension\HealthStatus\Config\HistoryRetentionConfig;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function sprintf;

/**
 * Cleans up old health check snapshots based on the retention policy.
 *
 * Runs periodically (default every 6 hours) and deletes snapshots
 * older than the configured maximum age.
 */
final readonly class HistoryCleanupJob implements JobInterface
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private HealthHistoryStoreInterface $store,
        private HistoryRetentionConfig $retention,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'health-status:cleanup';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::cron(sprintf('0 */%d * * *', $this->retention->cleanupIntervalHours));
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        try {
            $cutoff = $context->startedAt->modify(sprintf('-%d days', $this->retention->maxAgeDays));

            if (!$cutoff instanceof DateTimeImmutable) {
                $cutoff = new DateTimeImmutable(sprintf('-%d days', $this->retention->maxAgeDays));
            }

            $deleted = $this->store->deleteSnapshotsOlderThan($cutoff);

            return JobResult::success(
                $this->getName(),
                $context->startedAt,
                sprintf('Deleted %d snapshots older than %d days', $deleted, $this->retention->maxAgeDays),
            );
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $context->startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return sprintf('Removes health check snapshots older than %d days', $this->retention->maxAgeDays);
    }
}
