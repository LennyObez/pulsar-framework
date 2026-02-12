<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\StepUp;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

use function is_int;

/**
 * Configuration for step-up authentication limits and cooldowns.
 *
 * Controls how many step-up attempts are allowed before lockout,
 * the cooldown period between attempts, and the lockout duration.
 */
#[Api(since: '1.0.0')]
readonly class StepUpConfig
{
    /**
     * @param int $maxAttempts Maximum step-up attempts before lockout (must be >= 1)
     * @param int $cooldownSeconds Minimum seconds between attempts (0 = no cooldown)
     * @param int $lockoutSeconds Duration of lockout after max attempts exceeded
     * @param int $windowSeconds Sliding window for counting attempts
     */
    public function __construct(
        public int $maxAttempts = 5,
        public int $cooldownSeconds = 0,
        public int $lockoutSeconds = 900,
        public int $windowSeconds = 3600,
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('Step-up maxAttempts must be at least 1');
        }

        if ($this->cooldownSeconds < 0) {
            throw new InvalidArgumentException('Step-up cooldownSeconds must not be negative');
        }

        if ($this->lockoutSeconds < 0) {
            throw new InvalidArgumentException('Step-up lockoutSeconds must not be negative');
        }

        if ($this->windowSeconds < 1) {
            throw new InvalidArgumentException('Step-up windowSeconds must be at least 1');
        }
    }

    /**
     * Build from raw configuration array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            maxAttempts: is_int($data['max_attempts'] ?? null) ? $data['max_attempts'] : 5,
            cooldownSeconds: is_int($data['cooldown_seconds'] ?? null) ? $data['cooldown_seconds'] : 0,
            lockoutSeconds: is_int($data['lockout_seconds'] ?? null) ? $data['lockout_seconds'] : 900,
            windowSeconds: is_int($data['window_seconds'] ?? null) ? $data['window_seconds'] : 3600,
        );
    }
}
