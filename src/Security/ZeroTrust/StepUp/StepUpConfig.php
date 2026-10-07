<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\StepUp;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;
use Pulsar\Support\Coerce;

/**
 * Configuration for step-up authentication limits and cooldowns.
 *
 * Controls how many step-up attempts are allowed before lockout,
 * the cooldown period between attempts, and the lockout duration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StepUpConfig implements ReportsUnknownKeys
{
    /** Keys read from the `zero_trust.step_up` sub-array of config/security.php. */
    private const array KNOWN_KEYS = [
        'max_attempts', 'cooldown_seconds', 'lockout_seconds', 'window_seconds',
    ];

    /**
     * @param int $maxAttempts Maximum step-up attempts before lockout (must be >= 1)
     * @param int $cooldownSeconds Minimum seconds between attempts (0 = no cooldown)
     * @param int $lockoutSeconds Duration of lockout after max attempts exceeded
     * @param int $windowSeconds Sliding window for counting attempts
     * @param list<string> $unknownKeys Keys present in the raw `step_up` array that this
     *     DTO does not read — a misspelled `max_attempts` silently restores the default
     *     of 5, widening the lockout budget the operator meant to tighten.
     */
    public function __construct(
        public int $maxAttempts = 5,
        public int $cooldownSeconds = 0,
        public int $lockoutSeconds = 900,
        public int $windowSeconds = 3600,
        public array $unknownKeys = [],
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
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
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
            maxAttempts: Coerce::integerLike($data['max_attempts'] ?? null, 5),
            cooldownSeconds: Coerce::integerLike($data['cooldown_seconds'] ?? null, 0),
            lockoutSeconds: Coerce::integerLike($data['lockout_seconds'] ?? null, 900),
            windowSeconds: Coerce::integerLike($data['window_seconds'] ?? null, 3600),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
