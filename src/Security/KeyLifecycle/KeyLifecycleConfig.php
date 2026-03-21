<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for the key lifecycle engine.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class KeyLifecycleConfig
{
    /**
     * @param int $defaultRotationIntervalSeconds Default rotation interval (90 days)
     * @param int $defaultGracePeriodSeconds Grace period after rotation (24 hours)
     * @param list<int> $certificateWarningDays Days before expiry to warn
     * @param int $deployBlockDays Block deploy if cert expires within this many days
     */
    public function __construct(
        public int $defaultRotationIntervalSeconds = 7776000,
        public int $defaultGracePeriodSeconds = 86400,
        public array $certificateWarningDays = [30, 14, 7, 1],
        public int $deployBlockDays = 1,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int $rotationInterval */
        $rotationInterval = $data['default_rotation_interval_seconds'] ?? 7776000;
        /** @var int $gracePeriod */
        $gracePeriod = $data['default_grace_period_seconds'] ?? 86400;
        /** @var list<int> $warningDays */
        $warningDays = $data['certificate_warning_days'] ?? [30, 14, 7, 1];
        /** @var int $deployBlock */
        $deployBlock = $data['deploy_block_days'] ?? 1;

        return new self(
            defaultRotationIntervalSeconds: $rotationInterval,
            defaultGracePeriodSeconds: $gracePeriod,
            certificateWarningDays: $warningDays,
            deployBlockDays: $deployBlock,
        );
    }
}
