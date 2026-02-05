<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_scalar;
use function is_string;

/**
 * Typed configuration DTO for security headers.
 *
 * Maps from the `headers` key of `config/security.php`.
 */
#[Api(since: '1.0.0')]
readonly class SecurityHeadersConfig
{
    private const array MINIMUM_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-XSS-Protection' => '0',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    /**
     * @param array<string, string> $headers Header name => value pairs applied to every response
     */
    public function __construct(
        public array $headers,
    ) {}

    /**
     * Return the effective headers: minimum defaults merged with user config.
     *
     * User-configured headers take precedence over minimum defaults.
     *
     * @return array<string, string>
     */
    #[NoDiscard]
    public function effectiveHeaders(): array
    {
        return [...self::MINIMUM_HEADERS, ...$this->headers];
    }

    /**
     * Build from the raw security headers config array.
     *
     * @param array<string, mixed> $data Raw `headers` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $headers */
        $headers = array_map(static fn(mixed $value): string => is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''), $data);

        return new self(headers: $headers);
    }
}
