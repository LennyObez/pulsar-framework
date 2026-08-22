<?php

declare(strict_types=1);

namespace Pulsar\Live;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            endpointPrefix: Coerce::string($data['endpoint_prefix'] ?? null, '/_live'),
            debounceMs: Coerce::int($data['debounce_ms'] ?? null, 150),
            maxPayloadSize: Coerce::int($data['max_payload_size'] ?? null, 1_048_576),
            enablePolling: Coerce::strictBool($data['enable_polling'] ?? null, true),
            defaultPollIntervalMs: Coerce::int($data['default_poll_interval_ms'] ?? null, 2000),
            morphDom: Coerce::strictBool($data['morph_dom'] ?? null, true),
        );
    }
}
