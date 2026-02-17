<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use NoDiscard;
use Pulsar\Api\Api;

use function max;
use function min;

/**
 * Tracks client reputation for adaptive rate limiting.
 *
 * Clients with a history of successful requests earn a higher
 * reputation score, granting them increased rate limits. Clients
 * that repeatedly violate limits are penalized with lower scores.
 *
 * Score range: 0.0 (fully penalized) to 2.0 (trusted client).
 * Default: 1.0 (neutral).
 */
#[Api(since: '1.0.0')]
final readonly class ClientReputation
{
    private const float MIN_SCORE = 0.25;
    private const float MAX_SCORE = 2.0;
    private const float DEFAULT_SCORE = 1.0;
    private const float SUCCESS_INCREMENT = 0.01;
    private const float VIOLATION_PENALTY = 0.2;

    public function __construct(
        public float $score,
        public int $successCount,
        public int $violationCount,
    ) {}

    #[NoDiscard]
    public static function default(): self
    {
        return new self(
            score: self::DEFAULT_SCORE,
            successCount: 0,
            violationCount: 0,
        );
    }

    /**
     * Get the rate limit multiplier based on the current score.
     */
    #[NoDiscard]
    public function multiplier(): float
    {
        return $this->score;
    }

    /**
     * Record a successful request (improves reputation).
     */
    #[NoDiscard]
    public function recordSuccess(): self
    {
        return new self(
            score: min(self::MAX_SCORE, $this->score + self::SUCCESS_INCREMENT),
            successCount: $this->successCount + 1,
            violationCount: $this->violationCount,
        );
    }

    /**
     * Record a rate limit violation (degrades reputation).
     */
    #[NoDiscard]
    public function recordViolation(): self
    {
        return new self(
            score: max(self::MIN_SCORE, $this->score - self::VIOLATION_PENALTY),
            successCount: $this->successCount,
            violationCount: $this->violationCount + 1,
        );
    }
}
