<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for rate limiting settings.
 *
 * Maps from the `rate_limiting` key of `config/security.php`.
 */
#[Api]
readonly class RateLimitConfig
{
    public function __construct(
        public bool $enabled,
        public int $defaultLimit,
        public int $defaultWindow,
    ) {}

    /**
     * Build from the raw rate limit config array.
     *
     * @param array<string, mixed> $data Raw `rate_limiting` sub-array from config/security.php
     */
    public static function fromArray(array $data): self
    {
        $enabled = (bool) ($data['enabled'] ?? true);
        $defaultLimit = (int) ($data['default_limit'] ?? 60); // @phpstan-ignore cast.int
        $defaultWindow = (int) ($data['default_window'] ?? 60); // @phpstan-ignore cast.int

        return new self(
            enabled: $enabled,
            defaultLimit: $defaultLimit,
            defaultWindow: $defaultWindow,
        );
    }
}
