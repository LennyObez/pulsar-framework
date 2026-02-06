<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckInterface;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckResult;
use Pulsar\Supervisor\InvariantCheck\InvariantRunner;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;
use Pulsar\Supervisor\PreflightCheck\PreflightRunner;
use Random\RandomException;
use SodiumException;

use function time;

/**
 * Core supervisor responsible for worker lifecycle management,
 * stuck job detection, and self-healing operations.
 *
 * Coordinates recycle policies, preflight/invariant checks, and
 * stuck job recovery into a single entry point for the runtime.
 */
#[Internal]
final readonly class Supervisor
{
    private ?WorkerRecyclePolicy $recyclePolicy;

    private ?StuckJobPolicy $stuckJobPolicy;

    private PreflightRunner $preflightRunner;

    private InvariantRunner $invariantRunner;

    /**
     * @param list<PreflightCheckInterface>|null $preflightChecks
     * @param list<InvariantCheckInterface>|null  $invariantChecks
     */
    public function __construct(
        SupervisorConfig $config,
        ?WorkerRecyclePolicy $recyclePolicy = null,
        ?StuckJobPolicy $stuckJobPolicy = null,
        ?array $preflightChecks = null,
        ?array $invariantChecks = null,
        private ?LoggerInterface $logger = null,
        private ?AuditLogger $auditLogger = null,
    ) {
        $this->recyclePolicy = $recyclePolicy ?? ($config->enabled
            ? WorkerRecyclePolicy::fromConfig($config)
            : null);

        $this->stuckJobPolicy = $stuckJobPolicy ?? ($config->enabled
            ? StuckJobPolicy::fromConfig($config)
            : null);

        $this->preflightRunner = new PreflightRunner($preflightChecks ?? []);
        $this->invariantRunner = new InvariantRunner($invariantChecks ?? []);
    }

    /**
     * Evaluate whether a worker should be recycled given its current state.
     *
     * Returns a {@see RecycleRecord} describing the reason and chosen action
     * when a threshold is exceeded, or null when no recycle is needed.
     */
    public function shouldRecycle(
        int $requestCount,
        int $memoryUsageMb,
        int $uptimeSeconds,
    ): ?RecycleRecord {
        if ($this->recyclePolicy === null) {
            return null;
        }

        $now = time();

        if ($requestCount >= $this->recyclePolicy->maxRequests) {
            return new RecycleRecord(
                reason: RecycleReason::MaxRequests,
                action: RecycleAction::GracefulRestart,
                memoryUsageMb: $memoryUsageMb,
                requestCount: $requestCount,
                uptimeSeconds: $uptimeSeconds,
                performedAt: $now,
            );
        }

        if ($memoryUsageMb >= $this->recyclePolicy->memoryThresholdMb) {
            return new RecycleRecord(
                reason: RecycleReason::MemoryThreshold,
                action: RecycleAction::GracefulRestart,
                memoryUsageMb: $memoryUsageMb,
                requestCount: $requestCount,
                uptimeSeconds: $uptimeSeconds,
                performedAt: $now,
            );
        }

        if ($uptimeSeconds >= $this->recyclePolicy->timeLimitSeconds) {
            return new RecycleRecord(
                reason: RecycleReason::TimeLimit,
                action: RecycleAction::GracefulRestart,
                memoryUsageMb: $memoryUsageMb,
                requestCount: $requestCount,
                uptimeSeconds: $uptimeSeconds,
                performedAt: $now,
            );
        }

        return null;
    }

    /**
     * Detect stuck jobs using the configured policy and queue driver.
     *
     * @return list<JobRecord>
     */
    public function detectStuckJobs(QueueDriverInterface $driver): array
    {
        if ($this->stuckJobPolicy === null) {
            return [];
        }

        $detector = new StuckJobDetector($this->stuckJobPolicy, $driver);

        return $detector->detect();
    }

    /**
     * Recover a list of stuck jobs by dead-lettering them.
     *
     * @param list<JobRecord> $stuckJobs
     *
     * @return list<HealingAction>
     *
     * @throws RandomException If cryptographic random generation fails
     * @throws JsonException If JSON serialization fails during audit logging
     * @throws SodiumException If a sodium cryptographic operation fails during audit logging
     */
    public function recoverStuckJobs(array $stuckJobs, DeadLetterQueue $deadLetterQueue): array
    {
        $recovery = new StuckJobRecovery(
            $deadLetterQueue,
            $this->logger,
            $this->auditLogger,
        );

        $actions = [];

        foreach ($stuckJobs as $job) {
            $actions[] = $recovery->recover($job);
        }

        return $actions;
    }

    /**
     * Run all registered preflight checks.
     *
     * @return list<PreflightCheckResult>
     */
    public function runPreflightChecks(): array
    {
        return $this->preflightRunner->run();
    }

    /**
     * Run all registered invariant checks.
     *
     * @return list<InvariantCheckResult>
     */
    public function runInvariantChecks(): array
    {
        return $this->invariantRunner->run();
    }
}
