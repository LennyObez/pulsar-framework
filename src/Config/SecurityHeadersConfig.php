<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_scalar;
use function is_string;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for security headers.
 *
 * Maps from the `headers` key of `config/security.php`.
 */
#[Api]
readonly class SecurityHeadersConfig
{
    /**
     * @param array<string, string> $headers Header name => value pairs applied to every response
     */
    public function __construct(
        public array $headers,
    ) {}

    /**
     * Build from the raw security headers config array.
     *
     * @param array<string, mixed> $data Raw `headers` sub-array from config/security.php
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $headers */
        $headers = array_map(static fn(mixed $value): string => is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''), $data);

        return new self(headers: $headers);
    }
}
