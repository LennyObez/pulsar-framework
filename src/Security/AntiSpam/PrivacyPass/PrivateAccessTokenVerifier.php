<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\IssuerPublicKey;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivateToken;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\RsaSsaPssVerifier;
use Throwable;

use function base64_decode;
use function hash_equals;
use function strtr;

/**
 * Verifies redeemed Privacy Pass / Private Access Tokens at the Origin
 * (RFC 9577 / RFC 9578, token type 0x0002 — Blind RSA, publicly verifiable).
 *
 * A valid token proves the client passed an attester's checks (e.g. a genuine
 * device) without revealing its identity to the Origin. Verification is
 * stateless and consists of four constant-shape checks against the expected
 * {@see TokenChallenge}:
 *
 *  1. token_type is 0x0002;
 *  2. token_key_id echoes the configured issuer key (SHA-256 of its SPKI);
 *  3. challenge_digest matches the Origin's issued challenge;
 *  4. the authenticator is a valid RSASSA-PSS signature (SHA-384, MGF1-SHA-384,
 *     48-byte salt) over token_type ‖ nonce ‖ challenge_digest ‖ token_key_id.
 *
 * Requires ext-gmp (see {@see self::isSupported()}); without it no token can be
 * verified and {@see self::verify()} returns false (fail closed).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PrivateAccessTokenVerifier
{
    private RsaSsaPssVerifier $signature;

    public function __construct(
        private IssuerPublicKey $issuerKey,
        ?RsaSsaPssVerifier $signature = null,
    ) {
        $this->signature = $signature ?? new RsaSsaPssVerifier();
    }

    /**
     * Build a verifier from a base64url-encoded issuer SPKI (the `token-key`
     * parameter of a `WWW-Authenticate: PrivateToken` challenge).
     *
     * @throws InvalidArgumentException on a malformed key
     */
    #[NoDiscard]
    public static function fromBase64UrlKey(string $tokenKey): self
    {
        return new self(IssuerPublicKey::fromBase64Url($tokenKey));
    }

    public static function isSupported(): bool
    {
        return RsaSsaPssVerifier::isSupported();
    }

    /**
     * Verify a base64url-encoded token against the expected challenge.
     *
     * Never throws: any malformed input or unsupported environment yields false.
     */
    #[NoDiscard]
    public function verify(string $base64UrlToken, TokenChallenge $expectedChallenge): bool
    {
        try {
            $raw = self::base64UrlDecode($base64UrlToken);
            if ($raw === '') {
                return false;
            }

            $token = PrivateToken::parse($raw);

            if (!hash_equals($this->issuerKey->keyId(), $token->tokenKeyId)) {
                return false;
            }

            if (!hash_equals($expectedChallenge->digest(), $token->challengeDigest)) {
                return false;
            }

            return $this->signature->verify(
                $token->authenticatorInput(),
                $token->authenticator,
                $this->issuerKey->modulus,
                $this->issuerKey->exponent,
            );
        } catch (Throwable) {
            return false;
        }
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
