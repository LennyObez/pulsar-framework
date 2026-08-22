<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Transparent bot detection score for a request.
 *
 * Score range: 0 (definitely human) to 100 (definitely bot).
 * Individual signal scores are accumulated and clamped to [0, 100].
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BotScore
{
    /**
     * @param array<string, int> $signals Signal name => contribution to total score
     */
    public function __construct(
        public int $score,
        public array $signals,
    ) {}

    #[NoDiscard]
    public function isBot(int $threshold = 70): bool
    {
        return $this->score >= $threshold;
    }

    #[NoDiscard]
    public function isSuspicious(int $threshold = 40): bool
    {
        return $this->score >= $threshold;
    }

    #[NoDiscard]
    public function isHuman(int $threshold = 40): bool
    {
        return $this->score < $threshold;
    }
}
