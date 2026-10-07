<?php

declare(strict_types=1);

namespace Pulsar\Http\Htmx;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Configuration for the Pulsar hypermedia (px-*) runtime.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HtmxConfig
{
    public function __construct(
        public string $attributePrefix = 'px',
        public int $defaultSwapDelayMs = 0,
        public int $defaultSettleDelayMs = 20,
        public bool $includeIndicatorStyles = true,
        public bool $historyCacheEnabled = true,
        public int $historyCacheSize = 10,
        public bool $selfRequestsOnly = true,
        public string $csrfHeaderName = 'X-CSRF-Token',
    ) {}

    /**
     * @param array{
     *     attribute_prefix?: string,
     *     default_swap_delay_ms?: int,
     *     default_settle_delay_ms?: int,
     *     include_indicator_styles?: bool,
     *     history_cache_enabled?: bool,
     *     history_cache_size?: int,
     *     self_requests_only?: bool,
     *     csrf_header_name?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            attributePrefix: Coerce::string($data['attribute_prefix'] ?? null, 'px'),
            defaultSwapDelayMs: Coerce::int($data['default_swap_delay_ms'] ?? null, 0),
            defaultSettleDelayMs: Coerce::int($data['default_settle_delay_ms'] ?? null, 20),
            includeIndicatorStyles: Coerce::strictBool($data['include_indicator_styles'] ?? null, true),
            historyCacheEnabled: Coerce::strictBool($data['history_cache_enabled'] ?? null, true),
            historyCacheSize: Coerce::int($data['history_cache_size'] ?? null, 10),
            selfRequestsOnly: Coerce::strictBool($data['self_requests_only'] ?? null, true),
            csrfHeaderName: Coerce::string($data['csrf_header_name'] ?? null, 'X-CSRF-Token'),
        );
    }
}
