<?php

declare(strict_types=1);

namespace Pulsar\Http\Htmx;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            attributePrefix: is_string($data['attribute_prefix'] ?? null) ? $data['attribute_prefix'] : 'px',
            defaultSwapDelayMs: is_int($data['default_swap_delay_ms'] ?? null) ? $data['default_swap_delay_ms'] : 0,
            defaultSettleDelayMs: is_int($data['default_settle_delay_ms'] ?? null) ? $data['default_settle_delay_ms'] : 20,
            includeIndicatorStyles: is_bool($data['include_indicator_styles'] ?? null) ? $data['include_indicator_styles'] : true,
            historyCacheEnabled: is_bool($data['history_cache_enabled'] ?? null) ? $data['history_cache_enabled'] : true,
            historyCacheSize: is_int($data['history_cache_size'] ?? null) ? $data['history_cache_size'] : 10,
            selfRequestsOnly: is_bool($data['self_requests_only'] ?? null) ? $data['self_requests_only'] : true,
            csrfHeaderName: is_string($data['csrf_header_name'] ?? null) ? $data['csrf_header_name'] : 'X-CSRF-Token',
        );
    }
}
