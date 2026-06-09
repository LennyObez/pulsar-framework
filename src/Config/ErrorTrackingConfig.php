<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Typed configuration DTO for the error tracking section of observability config.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ErrorTrackingConfig
{
    /**
     * @param list<string> $sensitiveFields Additional sensitive field names to scrub
     */
    public function __construct(
        public bool $enabled = true,
        public int $maxGroups = 500,
        public int $maxRecentEventsPerGroup = 5,
        public array $sensitiveFields = [],
    ) {}

    /**
     * Build from the raw error tracking config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            maxGroups: Coerce::int($data['max_groups'] ?? null, 500),
            maxRecentEventsPerGroup: Coerce::int($data['max_recent_events_per_group'] ?? null, 5),
            sensitiveFields: Coerce::listOfString($data['sensitive_fields'] ?? null),
        );
    }
}
