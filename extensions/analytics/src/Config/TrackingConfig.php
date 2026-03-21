<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Config;

use Pulsar\Api\Api;

use function is_string;

/**
 * Tracker script and endpoint configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TrackingConfig
{
    /**
     * @param string $trackerEndpoint URL path for the collection endpoint
     * @param string $scriptEndpoint URL path for the tracker JavaScript
     * @param list<string> $extensions Enabled tracker extension modules (e.g., 'spa', 'outbound-links')
     */
    public function __construct(
        public string $trackerEndpoint = '/plsr/api/event',
        public string $scriptEndpoint = '/plsr/js/tracker.js',
        public array $extensions = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tracker = $data['tracker_endpoint'] ?? null;
        $script = $data['script_endpoint'] ?? null;
        return new self(
            trackerEndpoint: $tracker !== null ? (is_string($tracker) ? $tracker : '') : '/plsr/api/event',
            scriptEndpoint: $script !== null ? (is_string($script) ? $script : '') : '/plsr/js/tracker.js',
            extensions: array_values(array_filter(
                (array) ($data['extensions'] ?? []),
                static fn(mixed $v): bool => is_string($v) && $v !== '',
            )),
        );
    }
}
