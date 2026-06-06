<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use JsonException;
use Pulsar\Api\Api;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckResult;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;
use Random\RandomException;
use SodiumException;

#[Api(since: '1.0.0')]
interface SupervisorInterface
{
    public function shouldRecycle(int $requestCount, int $memoryUsageMb, int $uptimeSeconds): ?RecycleRecord;

    /**
     * @return list<JobRecord>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function detectStuckJobs(QueueDriverInterface $driver): array;

    /**
     * @param list<JobRecord> $stuckJobs
     *
     * @return list<HealingAction>
     *
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function recoverStuckJobs(array $stuckJobs, DeadLetterQueue $deadLetterQueue): array;

    /**
     * @return list<PreflightCheckResult>
     */
    public function runPreflightChecks(): array;

    /**
     * @return list<InvariantCheckResult>
     */
    public function runInvariantChecks(): array;
}
