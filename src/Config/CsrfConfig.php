<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function array_filter;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for CSRF protection settings.
 *
 * Maps from the `csrf` key of `config/security.php`.
 */
#[Api(since: '1.0.0')]
readonly class CsrfConfig
{
    /**
     * @param list<string> $trustedOrigins Canonical origins e.g. ['https://example.com', 'https://app.example.com:8443']
     * @param string $originValidation 'off'|'optional'|'required'
     */
    public function __construct(
        public bool $enabled,
        public int $tokenLength,
        public string $headerName,
        public string $formFieldName,
        public array $trustedOrigins = [],
        public string $originValidation = 'optional',
    ) {}

    /**
     * Build from the raw CSRF config array.
     *
     * @param array<string, mixed> $data Raw `csrf` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $enabled = (bool) ($data['enabled'] ?? true);
        $rawTokenLength = $data['token_length'] ?? 32;
        $tokenLength = is_int($rawTokenLength) ? $rawTokenLength : (int) (is_numeric($rawTokenLength) ? $rawTokenLength : 32);
        $rawHeaderName = $data['header_name'] ?? 'X-CSRF-Token';
        $headerName = is_string($rawHeaderName) ? $rawHeaderName : 'X-CSRF-Token';
        $rawFormFieldName = $data['form_field_name'] ?? '_csrf_token';
        $formFieldName = is_string($rawFormFieldName) ? $rawFormFieldName : '_csrf_token';
        /** @var list<string> $trustedOrigins */
        $trustedOrigins = is_array($data['trusted_origins'] ?? null) ? array_values(array_filter($data['trusted_origins'], is_string(...))) : [];
        $rawOriginValidation = $data['origin_validation'] ?? 'optional';
        $originValidation = is_string($rawOriginValidation) && in_array($rawOriginValidation, ['off', 'optional', 'required'], true)
            ? $rawOriginValidation
            : 'optional';

        return new self(
            enabled: $enabled,
            tokenLength: $tokenLength,
            headerName: $headerName,
            formFieldName: $formFieldName,
            trustedOrigins: $trustedOrigins,
            originValidation: $originValidation,
        );
    }
}
