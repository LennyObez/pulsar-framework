<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_int;
use function is_string;

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
        $rawTokenLength = $data['token_length'] ?? 32;
        $tokenLength = is_int($rawTokenLength) ? $rawTokenLength : (int) (is_numeric($rawTokenLength) ? $rawTokenLength : 32);
        $rawHeaderName = $data['header_name'] ?? 'X-CSRF-Token';
        $headerName = is_string($rawHeaderName) ? $rawHeaderName : 'X-CSRF-Token';
        $rawFormFieldName = $data['form_field_name'] ?? '_csrf_token';
        $formFieldName = is_string($rawFormFieldName) ? $rawFormFieldName : '_csrf_token';

        return new self(
            enabled: $enabled,
            tokenLength: $tokenLength,
            headerName: $headerName,
            formFieldName: $formFieldName,
        );
    }
}
