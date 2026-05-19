<?php

declare(strict_types=1);

namespace Pulsar\Security\SecurityTxt;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for auto-generating /.well-known/security.txt per RFC 9116.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9116
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityTxtConfig
{
    /**
     * @param list<string> $contacts     Contact URIs (mailto: or https:). At least one required by RFC 9116.
     * @param string       $expires      ISO 8601 expiry date (required by RFC 9116)
     * @param string       $encryption   URI to encryption key (optional)
     * @param string       $acknowledgments URI to security acknowledgments page (optional)
     * @param string       $policy       URI to vulnerability disclosure policy (optional)
     * @param list<string> $preferredLanguages  RFC 5646 language tags (optional)
     * @param string       $canonical    Canonical URI for this security.txt (optional)
     * @param list<string> $hiring       URIs to security job listings (optional)
     */
    public function __construct(
        public array $contacts = [],
        public string $expires = '',
        public string $encryption = '',
        public string $acknowledgments = '',
        public string $policy = '',
        public array $preferredLanguages = [],
        public string $canonical = '',
        public array $hiring = [],
    ) {}

    /**
     * @param array{
     *     contacts?: list<string>,
     *     expires?: string,
     *     encryption?: string,
     *     acknowledgments?: string,
     *     policy?: string,
     *     preferred_languages?: list<string>,
     *     canonical?: string,
     *     hiring?: list<string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            contacts: $data['contacts'] ?? [],
            expires: $data['expires'] ?? '',
            encryption: $data['encryption'] ?? '',
            acknowledgments: $data['acknowledgments'] ?? '',
            policy: $data['policy'] ?? '',
            preferredLanguages: $data['preferred_languages'] ?? [],
            canonical: $data['canonical'] ?? '',
            hiring: $data['hiring'] ?? [],
        );
    }
}
