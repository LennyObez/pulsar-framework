<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_int;

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
        $rawDefaultLimit = $data['default_limit'] ?? 60;
        $defaultLimit = is_int($rawDefaultLimit) ? $rawDefaultLimit : (int) (is_numeric($rawDefaultLimit) ? $rawDefaultLimit : 60);
        $rawDefaultWindow = $data['default_window'] ?? 60;
        $defaultWindow = is_int($rawDefaultWindow) ? $rawDefaultWindow : (int) (is_numeric($rawDefaultWindow) ? $rawDefaultWindow : 60);

        return new self(
            enabled: $enabled,
            defaultLimit: $defaultLimit,
            defaultWindow: $defaultWindow,
        );
    }
}
