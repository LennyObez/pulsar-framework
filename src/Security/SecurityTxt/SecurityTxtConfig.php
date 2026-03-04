<?php

declare(strict_types=1);

namespace Pulsar\Security\SecurityTxt;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * Configuration for auto-generating /.well-known/security.txt per RFC 9116.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9116
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $contacts */
        $contacts = is_array($data['contacts'] ?? null)
            ? array_values(array_filter($data['contacts'], is_string(...)))
            : [];

        /** @var list<string> $preferredLanguages */
        $preferredLanguages = is_array($data['preferred_languages'] ?? null)
            ? array_values(array_filter($data['preferred_languages'], is_string(...)))
            : [];

        /** @var list<string> $hiring */
        $hiring = is_array($data['hiring'] ?? null)
            ? array_values(array_filter($data['hiring'], is_string(...)))
            : [];

        $rawExpires = $data['expires'] ?? '';
        $rawEncryption = $data['encryption'] ?? '';
        $rawAcknowledgments = $data['acknowledgments'] ?? '';
        $rawPolicy = $data['policy'] ?? '';
        $rawCanonical = $data['canonical'] ?? '';

        return new self(
            contacts: $contacts,
            expires: is_string($rawExpires) ? $rawExpires : '',
            encryption: is_string($rawEncryption) ? $rawEncryption : '',
            acknowledgments: is_string($rawAcknowledgments) ? $rawAcknowledgments : '',
            policy: is_string($rawPolicy) ? $rawPolicy : '',
            preferredLanguages: $preferredLanguages,
            canonical: is_string($rawCanonical) ? $rawCanonical : '',
            hiring: $hiring,
        );
    }
}
