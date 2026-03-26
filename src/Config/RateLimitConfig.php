<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for rate limiting settings.
 *
 * Maps from the `rate_limiting` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RateLimitConfig
{
    public function __construct(
        public bool $enabled,
        public int $defaultLimit,
        public int $defaultWindow,
    ) {}

    /**
     * Build from the raw rate limit config array.
     *
     * @param array{
     *     enabled?: bool|int|string,
     *     default_limit?: int,
     *     default_window?: int,
     * } $data Raw `rate_limiting` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            defaultLimit: $data['default_limit'] ?? 60,
            defaultWindow: $data['default_window'] ?? 60,
        );
    }
}
