<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;

/**
 * Typed configuration DTO for `config/supervisor.php`.
 */
#[Api(since: '1.0.0')]
readonly class SupervisorConfig
{
    public function __construct(
        public bool $enabled = false,
        public int $recycleMaxRequests = 10000,
        public int $recycleMemoryThresholdMb = 256,
        public int $recycleTimeLimitSeconds = 7200,
        public int $stuckJobTimeoutSeconds = 300,
        public int $stuckJobCheckIntervalSeconds = 60,
        public bool $stuckJobMoveToDeadLetter = true,
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/supervisor.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('SUPERVISOR_ENABLED') !== null
            ? $environment->get('SUPERVISOR_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        /** @var array<string, mixed> $recycleData */
        $recycleData = $data['recycle'] ?? [];

        /** @var array<string, mixed> $stuckData */
        $stuckData = $data['stuck_job'] ?? [];

        $rawMaxRequests = $recycleData['max_requests'] ?? 10000;
        $rawMemThreshold = $recycleData['memory_threshold_mb'] ?? 256;
        $rawTimeLimit = $recycleData['time_limit_seconds'] ?? 7200;
        $rawStuckTimeout = $stuckData['timeout_seconds'] ?? 300;
        $rawCheckInterval = $stuckData['check_interval_seconds'] ?? 60;

        return new self(
            enabled: $enabled,
            recycleMaxRequests: is_int($rawMaxRequests) ? $rawMaxRequests : 10000,
            recycleMemoryThresholdMb: is_int($rawMemThreshold) ? $rawMemThreshold : 256,
            recycleTimeLimitSeconds: is_int($rawTimeLimit) ? $rawTimeLimit : 7200,
            stuckJobTimeoutSeconds: is_int($rawStuckTimeout) ? $rawStuckTimeout : 300,
            stuckJobCheckIntervalSeconds: is_int($rawCheckInterval) ? $rawCheckInterval : 60,
            stuckJobMoveToDeadLetter: (bool) ($stuckData['move_to_dead_letter'] ?? true),
        );
    }
}
