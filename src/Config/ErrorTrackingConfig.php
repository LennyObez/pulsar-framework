<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_int;
use function is_numeric;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for the error tracking section of observability config.
 */
#[Api]
readonly class ErrorTrackingConfig
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
    public static function fromArray(array $data): self
    {
        /** @var list<string> $sensitiveFields */
        $sensitiveFields = $data['sensitive_fields'] ?? [];

        $rawMaxGroups = $data['max_groups'] ?? 500;
        $rawMaxRecent = $data['max_recent_events_per_group'] ?? 5;

        $maxGroups = is_int($rawMaxGroups) ? $rawMaxGroups : (is_numeric($rawMaxGroups) ? (int) $rawMaxGroups : 500);
        $maxRecent = is_int($rawMaxRecent) ? $rawMaxRecent : (is_numeric($rawMaxRecent) ? (int) $rawMaxRecent : 5);

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            maxGroups: $maxGroups,
            maxRecentEventsPerGroup: $maxRecent,
            sensitiveFields: $sensitiveFields,
        );
    }
}
