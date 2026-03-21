<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;

/**
 * Typed configuration DTO for rate limiting settings.
 *
 * Maps from the `rate_limiting` key of `config/security.php`.
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
     * @param array<string, mixed> $data Raw `rate_limiting` sub-array from config/security.php
     */
    #[NoDiscard]
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
