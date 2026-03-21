<?php

declare(strict_types=1);

namespace Pulsar\Security\Canary;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Event emitted when a canary token is detected in an unexpected location.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CanaryAlertEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public CanaryToken $token,
        public string $detectedLocation,
        public DateTimeImmutable $detectedAt,
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function create(
        CanaryToken $token,
        string $detectedLocation,
        array $metadata = [],
    ): self {
        return new self(
            token: $token,
            detectedLocation: $detectedLocation,
            detectedAt: new DateTimeImmutable(),
            metadata: $metadata,
        );
    }
}
