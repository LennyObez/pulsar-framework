<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for CSRF protection settings.
 *
 * Maps from the `csrf` key of `config/security.php`.
 */
#[Api]
readonly class CsrfConfig
{
    public function __construct(
        public bool $enabled,
        public int $tokenLength,
        public string $headerName,
        public string $formFieldName,
    ) {}

    /**
     * Build from the raw CSRF config array.
     *
     * @param array<string, mixed> $data Raw `csrf` sub-array from config/security.php
     */
    public static function fromArray(array $data): self
    {
        $enabled = (bool) ($data['enabled'] ?? true);
        $tokenLength = (int) ($data['token_length'] ?? 32); // @phpstan-ignore cast.int
        $headerName = (string) ($data['header_name'] ?? 'X-CSRF-Token'); // @phpstan-ignore cast.string
        $formFieldName = (string) ($data['form_field_name'] ?? '_csrf_token'); // @phpstan-ignore cast.string

        return new self(
            enabled: $enabled,
            tokenLength: $tokenLength,
            headerName: $headerName,
            formFieldName: $formFieldName,
        );
    }
}
