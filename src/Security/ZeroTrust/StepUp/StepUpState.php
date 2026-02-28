<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\StepUp;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Tracks step-up authentication attempts for an identity.
 *
 * Records attempt history to enforce rate limiting, cooldown periods,
 * and lockout thresholds. Immutable: each state transition returns
 * a new instance.
 */
#[Api(since: '1.0.0')]
readonly class StepUpState
{
    /**
     * @param string $identityId Identity being tracked
     * @param int $attemptCount Number of step-up attempts in the current window
     * @param DateTimeImmutable|null $lastAttemptAt When the last attempt occurred
     * @param DateTimeImmutable|null $lockedUntil If set, the identity is locked out until this time
     * @param DateTimeImmutable $windowStart Start of the current attempt counting window
     */
    public function __construct(
        public string $identityId,
        public int $attemptCount = 0,
        public ?DateTimeImmutable $lastAttemptAt = null,
        public ?DateTimeImmutable $lockedUntil = null,
        public DateTimeImmutable $windowStart = new DateTimeImmutable(),
    ) {}

    /**
     * Whether the identity is currently locked out.
     */
    #[NoDiscard]
    public function isLockedOut(DateTimeImmutable $now = new DateTimeImmutable()): bool
    {
        return $this->lockedUntil !== null && $now < $this->lockedUntil;
    }

    /**
     * Whether a cooldown is active (too soon since last attempt).
     */
    #[NoDiscard]
    public function isCoolingDown(StepUpConfig $config, DateTimeImmutable $now = new DateTimeImmutable()): bool
    {
        if ($config->cooldownSeconds === 0 || $this->lastAttemptAt === null) {
            return false;
        }

        $elapsed = $now->getTimestamp() - $this->lastAttemptAt->getTimestamp();

        return $elapsed < $config->cooldownSeconds;
    }

    /**
     * Record an attempt and return the new state.
     *
     * If max attempts are exceeded, the returned state includes a lockout timestamp.
     */
    #[NoDiscard]
    public function recordAttempt(StepUpConfig $config, DateTimeImmutable $now = new DateTimeImmutable()): self
    {
        $windowStart = $this->windowStart;
        $attemptCount = $this->attemptCount;

        // Reset window if it has expired
        $elapsed = $now->getTimestamp() - $windowStart->getTimestamp();

        if ($elapsed >= $config->windowSeconds) {
            $windowStart = $now;
            $attemptCount = 0;
        }

        $attemptCount++;

        $lockedUntil = null;

        if ($attemptCount >= $config->maxAttempts) {
            $lockedUntil = $now->modify('+' . $config->lockoutSeconds . ' seconds');
        }

        return new self(
            identityId: $this->identityId,
            attemptCount: $attemptCount,
            lastAttemptAt: $now,
            lockedUntil: $lockedUntil,
            windowStart: $windowStart,
        );
    }

    /**
     * Reset the state (e.g., after successful step-up or admin override).
     */
    #[NoDiscard]
    public function reset(): self
    {
        return new self(identityId: $this->identityId);
    }
}
