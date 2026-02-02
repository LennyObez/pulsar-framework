<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Typed configuration DTO for security headers.
 *
 * Maps from the `headers` key of `config/security.php`.
 */
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
        $headers = [];

        foreach ($data as $name => $value) {
            $headers[$name] = (string) $value; // @phpstan-ignore cast.string
        }

        return new self(headers: $headers);
    }
}
