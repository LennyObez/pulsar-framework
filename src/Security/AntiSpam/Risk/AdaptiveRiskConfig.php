<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Configuration for adaptive, risk-based challenge escalation.
 *
 * Off by default. When enabled, requests scoring below the challenge threshold
 * pass with no friction; those in [challengeThreshold, blockThreshold) are
 * flagged for a challenge; those at or above blockThreshold are rejected.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdaptiveRiskConfig
{
    public function __construct(
        public bool $enabled = false,
        public float $challengeThreshold = 0.5,
        public float $blockThreshold = 0.9,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     challenge_threshold?: float|int|string,
     *     block_threshold?: float|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            challengeThreshold: Coerce::nullableFloat($data['challenge_threshold'] ?? null) ?? 0.5,
            blockThreshold: Coerce::nullableFloat($data['block_threshold'] ?? null) ?? 0.9,
        );
    }
}
