<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Config;

use Pulsar\Api\Api;

use function is_string;

/**
 * Tracker script and endpoint configuration.
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
        return new self(
            trackerEndpoint: (string) ($data['tracker_endpoint'] ?? '/plsr/api/event'),
            scriptEndpoint: (string) ($data['script_endpoint'] ?? '/plsr/js/tracker.js'),
            extensions: array_values(array_filter(
                (array) ($data['extensions'] ?? []),
                static fn(mixed $v): bool => is_string($v) && $v !== '',
            )),
        );
    }
}
