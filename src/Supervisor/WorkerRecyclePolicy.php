<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\SupervisorConfig;

/**
 * Policy governing when a worker process should be recycled.
 *
 * Encapsulates thresholds for request count, memory usage, and uptime.
 * When any threshold is exceeded the supervisor should initiate a
 * graceful restart of the affected worker.
 */
#[Api(since: '1.0.0')]
final readonly class WorkerRecyclePolicy
{
    public function __construct(
        public int $maxRequests,
        public int $memoryThresholdMb,
        public int $timeLimitSeconds,
    ) {}

    /**
     * Build a recycle policy from supervisor configuration.
     */
    #[NoDiscard]
    public static function fromConfig(SupervisorConfig $config): self
    {
        return new self(
            maxRequests: $config->recycleMaxRequests,
            memoryThresholdMb: $config->recycleMemoryThresholdMb,
            timeLimitSeconds: $config->recycleTimeLimitSeconds,
        );
    }
}
