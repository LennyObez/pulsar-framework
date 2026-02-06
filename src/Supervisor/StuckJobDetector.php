<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use Pulsar\Api\Internal;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

use function time;

/**
 * Detects stuck jobs by scanning for processing records that have
 * exceeded the configured timeout.
 *
 * A job is considered stuck when it has been in {@see JobRecordStatus::Processing}
 * status for longer than the policy's timeout threshold.
 */
#[Internal]
final class StuckJobDetector
{
    public function __construct(
        private readonly StuckJobPolicy $policy,
        private readonly QueueDriverInterface $driver,
    ) {}

    /**
     * Scan for jobs that are stuck in the processing state.
     *
     * @return list<JobRecord>
     */
    public function detect(): array
    {
        $processingJobs = $this->driver->findByStatus(JobRecordStatus::Processing);
        $now = time();
        $stuck = [];

        foreach ($processingJobs as $job) {
            $elapsed = $now - $job->availableAt;

            if ($elapsed >= $this->policy->timeoutSeconds) {
                $stuck[] = $job;
            }
        }

        return $stuck;
    }
}
