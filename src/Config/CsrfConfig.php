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
readonly class CsrfConfig
{
    /**
     * @param list<string> $trustedOrigins Additional origins accepted beyond the
     *     request's own (e.g. a separate admin domain): ['https://admin.example.com'].
     *     May be EMPTY — the middleware derives the expected origin from the
     *     request's own scheme+host, so a single-domain app needs no entry here.
     *     Behind a TLS terminator that does not rewrite the request scheme, add
     *     the public origin here as the escape hatch (see security-baseline.md).
     * @param string $originValidation 'off' | 'optional' | 'required'. Default
     *     'optional': a PRESENT-but-cross-origin signal is always rejected; a
     *     fully-absent signal (non-browser client) falls through to the token
     *     check. 'required' also rejects the fully-absent case. 'off' disables
     *     Layer 1 entirely — not recommended; the synchronizer token then stands
     *     alone. Note an empty $trustedOrigins no longer disables the layer.
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
