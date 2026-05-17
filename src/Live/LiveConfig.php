<?php

declare(strict_types=1);

namespace Pulsar\Live;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for Pulsar Live components.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LiveConfig
{
    public function __construct(
        public string $endpointPrefix = '/_live',
        public int $debounceMs = 150,
        public int $maxPayloadSize = 1_048_576,
        public bool $enablePolling = true,
        public int $defaultPollIntervalMs = 2000,
        public bool $morphDom = true,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            endpointPrefix: is_string($data['endpoint_prefix'] ?? null) ? $data['endpoint_prefix'] : '/_live',
            debounceMs: is_int($data['debounce_ms'] ?? null) ? $data['debounce_ms'] : 150,
            maxPayloadSize: is_int($data['max_payload_size'] ?? null) ? $data['max_payload_size'] : 1_048_576,
            enablePolling: is_bool($data['enable_polling'] ?? null) ? $data['enable_polling'] : true,
            defaultPollIntervalMs: is_int($data['default_poll_interval_ms'] ?? null) ? $data['default_poll_interval_ms'] : 2000,
            morphDom: is_bool($data['morph_dom'] ?? null) ? $data['morph_dom'] : true,
        );
    }
}
