<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     enabled?: bool|int|string,
     *     max_groups?: int,
     *     max_recent_events_per_group?: int,
     *     sensitive_fields?: list<string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            maxGroups: $data['max_groups'] ?? 500,
            maxRecentEventsPerGroup: $data['max_recent_events_per_group'] ?? 5,
            sensitiveFields: $data['sensitive_fields'] ?? [],
        );
    }
}
