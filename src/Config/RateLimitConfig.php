<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Typed configuration DTO for rate limiting settings.
 *
 * Maps from the `rate_limiting` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RateLimitConfig
{
    /**
     * @param string $keyStrategy Bucket key strategy: 'ip' (per client), 'route'
     *     (per endpoint, shared across clients), or 'ip_route' (per client per
     *     endpoint). Resolved to a {@see \Pulsar\Http\RateLimit\RateLimitKeyStrategy}
     *     at wiring time so this DTO stays free of an Http dependency.
     */
    public function __construct(
        public bool $enabled,
        public int $defaultLimit,
        public int $defaultWindow,
        public string $keyStrategy = 'ip',
    ) {}

    /**
     * Build from the raw rate limit config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            defaultLimit: Coerce::int($data['default_limit'] ?? null, 60),
            defaultWindow: Coerce::int($data['default_window'] ?? null, 60),
            keyStrategy: Coerce::string($data['key_strategy'] ?? null, 'ip'),
        );
    }
}
