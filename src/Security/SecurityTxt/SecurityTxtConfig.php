<?php

declare(strict_types=1);

namespace Pulsar\Security\SecurityTxt;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            contacts: Coerce::listOfString($data['contacts'] ?? null),
            expires: Coerce::string($data['expires'] ?? null),
            encryption: Coerce::string($data['encryption'] ?? null),
            acknowledgments: Coerce::string($data['acknowledgments'] ?? null),
            policy: Coerce::string($data['policy'] ?? null),
            preferredLanguages: Coerce::listOfString($data['preferred_languages'] ?? null),
            canonical: Coerce::string($data['canonical'] ?? null),
            hiring: Coerce::listOfString($data['hiring'] ?? null),
        );
    }
}
