<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use Pulsar\Api\Api;

/**
 * Defines the rotation schedule for a specific key.
 */
#[Api(since: '1.0.0')]
final readonly class RotationSchedule
{
    public function __construct(
        public string $kid,
        public int $intervalSeconds,
        public int $gracePeriodSeconds,
    ) {}

    /**
     * @return array{kid: string, interval_seconds: int, grace_period_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'kid' => $this->kid,
            'interval_seconds' => $this->intervalSeconds,
            'grace_period_seconds' => $this->gracePeriodSeconds,
        ];
    }
}
