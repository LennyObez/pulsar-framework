<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\TimeTrap;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function pack;
use function rtrim;
use function sodium_crypto_auth;
use function sodium_crypto_auth_verify;
use function strlen;
use function strtr;
use function substr;
use function time;
use function unpack;

use const SODIUM_CRYPTO_AUTH_BYTES;
use const SODIUM_CRYPTO_AUTH_KEYBYTES;

/**
 * Stateless signer/parser for time-trap render stamps.
 *
 * Mints and verifies a tamper-proof token carrying `{issuedAt, formId}` signed
 * with `sodium_crypto_auth` (HMAC-SHA-512/256, constant-time verify) keyed by a
 * derived master sub-key. No per-issuance server storage: the timestamp and the
 * form binding are signed in, so a client can neither backdate the stamp (to
 * beat the minimum fill time) nor replay it against a different form.
 *
 * Mirrors {@see \Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService}
 * but is independent of it (distinct KDF sub-key) and requires no JavaScript:
 * the stamp travels in a server-rendered hidden field.
 */
#[Internal(reason: 'Time-trap crypto core; consumed via TimeTrapCheck/TimeTrapRenderer')]
final readonly class TimeTrapService
{
    /** Bytes of the fixed-width prefix of the signed payload: pack('J'). */
    private const int TIMESTAMP_BYTES = 8;

    /** Defence-in-depth bound on the form identifier length. */
    private const int MAX_FORM_ID_BYTES = 128;

    public function __construct(
        #[SensitiveParameter]
        private string $signingKey,
    ) {}

    /**
     * Mint a fresh stamp for the given form at the current time.
     */
    #[NoDiscard]
    public function mint(string $formId): TimeTrapToken
    {
        return new TimeTrapToken(issuedAt: time(), formId: $formId);
    }

    /**
     * Mint and sign a fresh stamp for embedding in a form.
     */
    #[NoDiscard]
    public function issue(string $formId): string
    {
        return $this->sign($this->mint($formId));
    }

    /**
     * Encode and HMAC-sign a stamp into a base64url token.
     */
    #[NoDiscard]
    public function sign(TimeTrapToken $token): string
    {
        $payload = pack('J', $token->issuedAt) . $token->formId;
        $mac = sodium_crypto_auth($payload, $this->signingKey);

        return self::base64UrlEncode($payload . $mac);
    }

    /**
     * Decode and verify a token's signature, returning the stamp.
     *
     * Returns null on a malformed token or an invalid signature. Does NOT check
     * the fill-time window — {@see TimeTrapCheck} owns that policy.
     */
    #[NoDiscard]
    public function parse(string $token): ?TimeTrapToken
    {
        $raw = self::base64UrlDecode($token);

        if ($raw === null) {
            return null;
        }

        $length = strlen($raw);

        // Must hold at least the timestamp + the MAC, and the form id portion
        // must not exceed the defence-in-depth bound.
        if (
            $length < self::TIMESTAMP_BYTES + SODIUM_CRYPTO_AUTH_BYTES
            || $length > self::TIMESTAMP_BYTES + self::MAX_FORM_ID_BYTES + SODIUM_CRYPTO_AUTH_BYTES
        ) {
            return null;
        }

        $payload = substr($raw, 0, $length - SODIUM_CRYPTO_AUTH_BYTES);
        $mac = substr($raw, $length - SODIUM_CRYPTO_AUTH_BYTES);

        if (!sodium_crypto_auth_verify($mac, $payload, $this->signingKey)) {
            return null;
        }

        /** @var array{1: int} $issuedAt */
        $issuedAt = unpack('J', substr($payload, 0, self::TIMESTAMP_BYTES));

        return new TimeTrapToken(
            issuedAt: $issuedAt[1],
            formId: substr($payload, self::TIMESTAMP_BYTES),
        );
    }

    /**
     * Whether a key of the required length is present (time-trap usable).
     */
    #[NoDiscard]
    public function hasValidKey(): bool
    {
        return strlen($this->signingKey) === SODIUM_CRYPTO_AUTH_KEYBYTES;
    }

    private static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $token): ?string
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
