<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Idempotency\Exception\IdempotencyException;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\SubKeyId;
use SodiumException;

use function base64_decode;
use function base64_encode;
use function hash_equals;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sodium_bin2hex;
use function sodium_crypto_generichash;
use function sodium_memzero;
use function strlen;
use function substr;

/**
 * Signed integrity envelope for cached idempotency payloads.
 *
 * A DB-backed idempotency store with write access compromise can
 * substitute the cached result for an idempotency key and replay a forged
 * response on the next idempotent retry. The envelope binds each payload
 * to the idempotency key it was generated for via a libsodium BLAKE2b
 * keyed hash (HMAC) derived from the master key (subkey id 12, context
 * `idemcach`). On retrieval the signature is recomputed and compared in
 * constant time; any mismatch is treated as tampering and surfaces as
 * `IdempotencyException::tamperedPayload()`.
 *
 * The signature covers the schema version, the key identifier, the
 * idempotency key bytes, and the raw payload bytes (length-prefixed,
 * matching the AuditEntry pattern). Binding the key prevents lifting a
 * sealed payload from one row and replaying it under a different
 * idempotency key.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SignedIdempotencyEnvelope
{
    /**
     * KDF subkey id reserved for idempotency-cache HMAC. Sourced from the
     * framework-wide {@see SubKeyId} registry (ADR-0006) so the registry is
     * the single source of truth and a future drift between this envelope
     * and the registry becomes a compile-time impossibility.
     */
    private const int SUB_KEY_ID = SubKeyId::IdempotencyEnvelope->value;

    /**
     * 8-byte KDF context separating this signing key from any other
     * subkey derived from the same master key.
     */
    private const string KDF_CONTEXT = 'idemcach';

    /**
     * Envelope schema version. Bumped on any incompatible change to the
     * signed message layout. Stored payloads carrying a different
     * version are rejected as tampered.
     */
    private const int SCHEMA_VERSION = 1;

    /**
     * Key-id length: 16 hex chars (64 bits). Matches `MasterKey::keyId()`
     * so the envelope's `kid` reads identically to other framework
     * subkey identifiers.
     */
    private const int KEY_ID_LENGTH = 16;

    public function __construct(
        private KeyProviderInterface $keyProvider,
    ) {}

    /**
     * Wrap a payload with a HMAC envelope bound to its idempotency key.
     *
     * The returned string is the value to hand to
     * {@see IdempotencyStoreInterface::commit()} — callers should treat
     * it as opaque and round-trip it through {@see open()} on retrieval.
     *
     * @throws JsonException
     * @throws SodiumException
     */
    #[NoDiscard]
    public function seal(string $idempotencyKey, string $payload): string
    {
        $signingKey = $this->keyProvider->deriveSubKey(self::SUB_KEY_ID, self::KDF_CONTEXT);

        try {
            $kid = self::computeKid($signingKey);
            $message = self::buildMessage(self::SCHEMA_VERSION, $kid, $idempotencyKey, $payload);
            $hmac = Hmac::computeHex($message, $signingKey);
        } finally {
            sodium_memzero($signingKey);
        }

        return json_encode([
            'v' => self::SCHEMA_VERSION,
            'kid' => $kid,
            'h' => $hmac,
            // Payload is base64-encoded so the JSON envelope stays
            // structurally stable regardless of what the caller stores
            // (JSON, binary, etc.) and so JSON decoders cannot be tricked
            // into reinterpreting embedded JSON during transport.
            'p' => base64_encode($payload),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Verify and unwrap a sealed envelope.
     *
     * @throws IdempotencyException If the envelope is malformed,
     *                              schema-version mismatched, or the
     *                              HMAC does not match the recomputed
     *                              signature.
     * @throws JsonException
     * @throws SodiumException
     */
    #[NoDiscard]
    public function open(string $idempotencyKey, string $sealed): string
    {
        /** @var array<string, mixed>|null $envelope */
        $envelope = json_decode($sealed, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($envelope)) {
            throw IdempotencyException::tamperedPayload($idempotencyKey, 'envelope is not a JSON object');
        }

        $version = $envelope['v'] ?? null;
        $kid = $envelope['kid'] ?? null;
        $hmac = $envelope['h'] ?? null;
        $encodedPayload = $envelope['p'] ?? null;

        if (!is_int($version) || $version !== self::SCHEMA_VERSION) {
            throw IdempotencyException::tamperedPayload($idempotencyKey, 'unknown envelope schema version');
        }

        if (!is_string($kid) || strlen($kid) !== self::KEY_ID_LENGTH) {
            throw IdempotencyException::tamperedPayload($idempotencyKey, 'missing or malformed key id');
        }

        if (!is_string($hmac) || $hmac === '') {
            throw IdempotencyException::tamperedPayload($idempotencyKey, 'missing signature');
        }

        if (!is_string($encodedPayload)) {
            throw IdempotencyException::tamperedPayload($idempotencyKey, 'missing payload');
        }

        $payload = base64_decode($encodedPayload, true);

        if ($payload === false) {
            throw IdempotencyException::tamperedPayload($idempotencyKey, 'payload is not valid base64');
        }

        $signingKey = $this->keyProvider->deriveSubKey(self::SUB_KEY_ID, self::KDF_CONTEXT);

        try {
            $expectedKid = self::computeKid($signingKey);

            // Reject envelopes signed under a different key bytes (rotated
            // master key without matching previous-key support, or
            // attacker-supplied envelope with a forged kid). The HMAC check
            // below is the actual integrity gate; this short-circuit gives
            // a clearer error and avoids needless HMAC recomputation.
            if (!hash_equals($expectedKid, $kid)) {
                throw IdempotencyException::tamperedPayload($idempotencyKey, 'key id mismatch');
            }

            $message = self::buildMessage($version, $kid, $idempotencyKey, $payload);
            $expectedHmac = Hmac::computeHex($message, $signingKey);
        } finally {
            sodium_memzero($signingKey);
        }

        if (!hash_equals($expectedHmac, $hmac)) {
            throw IdempotencyException::tamperedPayload($idempotencyKey, 'signature mismatch');
        }

        return $payload;
    }

    /**
     * Compute a stable 16-hex-char identifier for the signing key bytes.
     * Mirrors `MasterKey::keyId()` but accepts a raw key directly so the
     * envelope can derive once and reuse the bytes for both `kid` and
     * HMAC computation.
     *
     * @throws SodiumException
     */
    private static function computeKid(string $signingKey): string
    {
        $hash = sodium_crypto_generichash($signingKey, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN);

        return substr(sodium_bin2hex($hash), 0, self::KEY_ID_LENGTH);
    }

    /**
     * Build the HMAC-covered message using the same length-prefixed
     * encoding as `AuditEntry::buildMessage()` so callers cannot collapse
     * two different (key, payload) tuples into a single signed string.
     */
    private static function buildMessage(int $version, string $kid, string $idempotencyKey, string $payload): string
    {
        $fields = [(string) $version, $kid, $idempotencyKey, $payload];
        $parts = [];

        foreach ($fields as $field) {
            $parts[] = strlen($field) . ':' . $field;
        }

        return implode("\n", $parts);
    }
}
