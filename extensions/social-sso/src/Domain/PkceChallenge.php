<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Domain;

use NoDiscard;
use Pulsar\Api\Api;

use function base64_encode;
use function hash;
use function random_bytes;
use function rtrim;
use function str_replace;

/**
 * Immutable PKCE (Proof Key for Code Exchange) challenge pair.
 *
 * Generates a cryptographically random code verifier and its
 * SHA-256 challenge per RFC 7636. Only the S256 method is supported.
 */
#[Api(since: '1.0.0')]
final readonly class PkceChallenge
{
    private const string METHOD = 'S256';

    /**
     * @param string $verifier  Code verifier (base64url-encoded, 43 chars)
     * @param string $challenge Code challenge (base64url-encoded SHA-256 of verifier)
     * @param string $method    Challenge method (always "S256")
     */
    public function __construct(
        public string $verifier,
        public string $challenge,
        public string $method = self::METHOD,
    ) {}

    /**
     * Generate a new PKCE challenge pair.
     *
     * Produces a 32-byte random verifier encoded as base64url (43 chars),
     * then computes the SHA-256 hash of the verifier as the challenge.
     */
    #[NoDiscard]
    public static function generate(): self
    {
        $verifier = self::base64UrlEncode(random_bytes(32));
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));

        return new self($verifier, $challenge, self::METHOD);
    }

    /**
     * Base64url-encode without padding per RFC 4648 Section 5.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($data)), '=');
    }
}
