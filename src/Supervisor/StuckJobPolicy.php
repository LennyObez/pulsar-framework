<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\SupervisorConfig;

/**
 * Policy governing stuck job detection and recovery.
 *
 * Defines the timeout after which a processing job is considered stuck,
 * the interval between detection sweeps, and whether stuck jobs should
 * be moved to the dead-letter queue automatically.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StuckJobPolicy
{
    public function __construct(
        public int $timeoutSeconds,
        public int $checkIntervalSeconds,
        public bool $moveToDeadLetter,
    ) {}

    /**
     * Build a stuck-job policy from supervisor configuration.
     */
    #[NoDiscard]
    public static function fromConfig(SupervisorConfig $config): self
    {
        return new self(
            timeoutSeconds: $config->stuckJobTimeoutSeconds,
            checkIntervalSeconds: $config->stuckJobCheckIntervalSeconds,
            moveToDeadLetter: $config->stuckJobMoveToDeadLetter,
        );
    }
}
