<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function in_array;
use function is_string;

/**
 * Typed configuration DTO for CSRF protection settings.
 *
 * Maps from the `csrf` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CsrfConfig
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
     * @param array{
     *     enabled?: bool|int|string,
     *     token_length?: int,
     *     header_name?: string,
     *     form_field_name?: string,
     *     trusted_origins?: list<string>,
     *     origin_validation?: string,
     * } $data Raw `csrf` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $trustedOrigins = array_values(array_filter($data['trusted_origins'] ?? [], is_string(...)));
        $rawOriginValidation = $data['origin_validation'] ?? 'optional';
        $originValidation = in_array($rawOriginValidation, ['off', 'optional', 'required'], true)
            ? $rawOriginValidation
            : 'optional';

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            tokenLength: $data['token_length'] ?? 32,
            headerName: $data['header_name'] ?? 'X-CSRF-Token',
            formFieldName: $data['form_field_name'] ?? '_csrf_token',
            trustedOrigins: $trustedOrigins,
            originValidation: $originValidation,
        );
    }
}
