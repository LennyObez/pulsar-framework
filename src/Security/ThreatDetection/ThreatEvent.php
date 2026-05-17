<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable DTO representing a detected threat event.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThreatEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ThreatCategory $category,
        public ThreatResponse $recommendedAction,
        public string $sourceIp,
        public string $description,
        public float $confidence,
        public DateTimeImmutable $detectedAt,
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function create(
        ThreatCategory $category,
        ThreatResponse $recommendedAction,
        string $sourceIp,
        string $description,
        float $confidence = 1.0,
        array $metadata = [],
    ): self {
        return new self(
            category: $category,
            recommendedAction: $recommendedAction,
            sourceIp: $sourceIp,
            description: $description,
            confidence: $confidence,
            detectedAt: new DateTimeImmutable(),
            metadata: $metadata,
        );
    }
}
