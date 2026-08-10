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
readonly class CsrfConfig implements ReportsUnknownKeys
{
    /** Keys read from the `csrf` sub-array of config/security.php. */
    private const array KNOWN_KEYS = [
        'enabled', 'token_length', 'header_name', 'form_field_name',
        'trusted_origins', 'origin_validation',
    ];

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
     *     alone. Note an empty $trustedOrigins does not disable the layer.
     * @param list<string> $unknownKeys Keys present in the raw `csrf` array that this
     *     DTO does not read — reported at boot rather than silently ignored, since a
     *     misspelled key here leaves a CSRF control at its default instead of the
     *     value the operator intended.
     */
    public function __construct(
        public bool $enabled,
        public int $tokenLength,
        public string $headerName,
        public string $formFieldName,
        public array $trustedOrigins = [],
        public string $originValidation = 'optional',
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
