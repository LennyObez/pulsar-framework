<?php

declare(strict_types=1);

namespace Pulsar\Live;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     endpoint_prefix?: string,
     *     debounce_ms?: int,
     *     max_payload_size?: int,
     *     enable_polling?: bool,
     *     default_poll_interval_ms?: int,
     *     morph_dom?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            endpointPrefix: $data['endpoint_prefix'] ?? '/_live',
            debounceMs: $data['debounce_ms'] ?? 150,
            maxPayloadSize: $data['max_payload_size'] ?? 1_048_576,
            enablePolling: $data['enable_polling'] ?? true,
            defaultPollIntervalMs: $data['default_poll_interval_ms'] ?? 2000,
            morphDom: $data['morph_dom'] ?? true,
        );
    }
}
