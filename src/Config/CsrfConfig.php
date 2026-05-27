<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function in_array;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawOriginValidation = Coerce::string($data['origin_validation'] ?? null, 'optional');
        $originValidation = in_array($rawOriginValidation, ['off', 'optional', 'required'], true)
            ? $rawOriginValidation
            : 'optional';

        return new self(
            enabled: Coerce::bool($data['enabled'] ?? null, true),
            tokenLength: Coerce::int($data['token_length'] ?? null, 32),
            headerName: Coerce::string($data['header_name'] ?? null, 'X-CSRF-Token'),
            formFieldName: Coerce::string($data['form_field_name'] ?? null, '_csrf_token'),
            trustedOrigins: Coerce::listOfString($data['trusted_origins'] ?? null),
            originValidation: $originValidation,
        );
    }
}
