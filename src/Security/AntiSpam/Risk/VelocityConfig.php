<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Configuration for the request-velocity risk signal.
 *
 * Counts a client's requests in a fixed window and contributes risk once the
 * rate exceeds $threshold, scaling up to $maxScore — so a client hammering the
 * origin looks riskier than a normal browser. Self-hosted and per-origin: it
 * sees only your own traffic, which is exactly the local-reputation signal the
 * engine lacks out of the box.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VelocityConfig
{
    public function __construct(
        public bool $enabled = false,
        public int $threshold = 120,
        public int $windowSeconds = 60,
        public float $maxScore = 0.7,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     threshold?: int|string,
     *     window_seconds?: int|string,
     *     max_score?: float|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            threshold: Coerce::int($data['threshold'] ?? null, 120),
            windowSeconds: Coerce::int($data['window_seconds'] ?? null, 60),
            maxScore: Coerce::nullableFloat($data['max_score'] ?? null) ?? 0.7,
        );
    }
}
