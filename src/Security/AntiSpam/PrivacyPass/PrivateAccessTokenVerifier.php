<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\IssuerPublicKey;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivateToken;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\RsaSsaPssVerifier;
use Throwable;

use function base64_decode;
use function bin2hex;
use function hash_equals;
use function max;
use function strtr;

/**
 * Verifies redeemed Privacy Pass / Private Access Tokens at the Origin
 * (RFC 9577 / RFC 9578, token type 0x0002 — Blind RSA, publicly verifiable).
 *
 * A valid token proves the client passed an attester's checks without revealing
 * its identity to the Origin. Verification:
 *
 *  1. token_type is 0x0002;
 *  2. token_key_id selects a configured issuer key (supports rotation — many
 *     keys may be trusted at once);
 *  3. challenge_digest matches the Origin's issued challenge;
 *  4. the authenticator is a valid RSASSA-PSS signature (SHA-384, MGF1-SHA-384,
 *     48-byte salt) over token_type ‖ nonce ‖ challenge_digest ‖ token_key_id;
 *  5. (single-use) the token's nonce has not been seen before — enforced via the
 *     replay cache, so a captured token cannot be replayed. Without a cache this
 *     degrades to reuse-within-lifetime (the caller should log the downgrade).
 *
 * Requires ext-gmp (see {@see self::isSupported()}); without it no token can be
 * verified and {@see self::verify()} returns false (fail closed). Construct via
 * the {@see self::fromBase64UrlKey()} / {@see self::fromBase64UrlKeys()}
 * factories — the issuer key never appears in the public surface.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PrivateAccessTokenVerifier
{
    private const string REPLAY_TAG = 'privacy-pass-nonce';

    /**
     * @param array<string, IssuerPublicKey> $keysByKeyId hex(token_key_id) => key
     */
    private function __construct(
        private array $keysByKeyId,
        private RsaSsaPssVerifier $signature,
        private ?TaggedCacheInterface $replayCache,
        private bool $singleUse,
        private int $singleUseTtlSeconds,
    ) {}

    /**
     * Build a verifier from a single base64url issuer SPKI (the `token-key`
     * parameter of a `WWW-Authenticate: PrivateToken` challenge).
     *
     * @throws InvalidArgumentException on a malformed key
     */
    #[NoDiscard]
    public static function fromBase64UrlKey(
        string $tokenKey,
        ?TaggedCacheInterface $replayCache = null,
        bool $singleUse = true,
        int $singleUseTtlSeconds = 86400,
    ): self {
        return self::fromBase64UrlKeys([$tokenKey], $replayCache, $singleUse, $singleUseTtlSeconds);
    }

    /**
     * Build a verifier trusting several issuer keys at once (key rotation).
     *
     * @param list<string> $tokenKeys base64url issuer SPKIs
     *
     * @throws InvalidArgumentException when no valid key is provided
     */
    #[NoDiscard]
    public static function fromBase64UrlKeys(
        array $tokenKeys,
        ?TaggedCacheInterface $replayCache = null,
        bool $singleUse = true,
        int $singleUseTtlSeconds = 86400,
    ): self {
        $keys = [];
        foreach ($tokenKeys as $tokenKey) {
            $key = IssuerPublicKey::fromBase64Url($tokenKey);
            $keys[bin2hex($key->keyId())] = $key;
        }

        if ($keys === []) {
            throw new InvalidArgumentException('At least one issuer key is required.');
        }

        return new self($keys, new RsaSsaPssVerifier(), $replayCache, $singleUse, $singleUseTtlSeconds);
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

            $key = $this->keysByKeyId[bin2hex($token->tokenKeyId)] ?? null;
            if ($key === null) {
                return false;
            }

            if (!hash_equals($expectedChallenge->digest(), $token->challengeDigest)) {
                return false;
            }

            $signatureValid = $this->signature->verify(
                $token->authenticatorInput(),
                $token->authenticator,
                $key->modulus,
                $key->exponent,
            );

            if (!$signatureValid) {
                return false;
            }

            // Single-use is enforced only after the signature is proven valid, so
            // an attacker cannot burn nonces or poison the cache with forged tokens.
            return $this->consumeNonce($token->nonce);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return bool true if the nonce is fresh (or single-use is off / no cache)
     */
    private function consumeNonce(string $nonce): bool
    {
        if (!$this->singleUse || $this->replayCache === null) {
            return true;
        }

        $key = 'privacy-pass:nonce:' . bin2hex($nonce);

        if ($this->replayCache->get($key) !== null) {
            return false;
        }

        $this->replayCache->set($key, '1', [self::REPLAY_TAG], max(1, $this->singleUseTtlSeconds));

        return true;
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
