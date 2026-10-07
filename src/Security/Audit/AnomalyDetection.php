<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable record of a detected anomaly.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AnomalyDetection
{
    public function __construct(
        public AnomalyRule $rule,
        public string $actor,
        public int $eventCount,
        public float $detectedAt,
    ) {}

    /**
     * Create a detection from a fired rule.
     */
    #[NoDiscard]
    public static function fromRule(AnomalyRule $rule, string $actor, int $eventCount): self
    {
        return new self(
            rule: $rule,
            actor: $actor,
            eventCount: $eventCount,
            detectedAt: microtime(true),
        );
    }
}
