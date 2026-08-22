<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\ManagedChallenge;

use NoDiscard;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function count;
use function explode;
use function hash;
use function hex2bin;
use function max;
use function ord;
use function pack;
use function random_bytes;
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
 * Self-hosted, privacy-preserving proof-of-work challenge engine.
 *
 * Mints and verifies stateless, HMAC-signed challenges with no external
 * service, no fingerprinting, and no per-issuance server storage. A challenge
 * carries `{id, difficultyBits, issuedAt}` signed with `sodium_crypto_auth`
 * (HMAC-SHA-512/256, constant-time verify), keyed by a derived master sub-key.
 *
 * The client solves the puzzle (find `solution` such that
 * `SHA-256(id . '.' . solution)` has >= `difficultyBits` leading zero bits) and
 * submits `challengeToken . '.' . solution`. Verification checks, in order:
 * signature, freshness (TTL window), proof-of-work, and single-use (replay
 * cache). Difficulty and issue time are signed in, so a client cannot weaken
 * the puzzle or replay an expired one.
 */
#[Internal(reason: 'Managed challenge crypto core; consumed via ManagedChallengeVerifier/Renderer')]
final readonly class ManagedChallengeService
{
    /** Bytes of the signed payload: pack('J') + pack('N') + 16-byte id. */
    private const int PAYLOAD_BYTES = 8 + 4 + 16;

    /** Defence-in-depth bound on the client-supplied solution length. */
    private const int MAX_SOLUTION_LENGTH = 64;

    private const string CACHE_TAG = 'antispam_managed_challenge';

    public function __construct(
        #[SensitiveParameter]
        private string $signingKey,
        private int $difficultyBits = 16,
        private int $ttlSeconds = 300,
        private ?TaggedCacheInterface $cache = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Create a fresh (unsigned) challenge with a random id at the current time.
     */
    #[NoDiscard]
    public function mint(): ManagedChallenge
    {
        return new ManagedChallenge(
            id: bin2hex(random_bytes(16)),
            bits: $this->difficultyBits,
            issuedAt: time(),
        );
    }

    /**
     * Mint a fresh signed challenge token for embedding in a page.
     */
    #[NoDiscard]
    public function issue(): string
    {
        return $this->sign($this->mint());
    }

    /**
     * Encode and HMAC-sign a challenge into a base64url token.
     */
    #[NoDiscard]
    public function sign(ManagedChallenge $challenge): string
    {
        $idBytes = hex2bin($challenge->id);
        $payload = pack('J', $challenge->issuedAt) . pack('N', $challenge->bits) . $idBytes;
        $mac = sodium_crypto_auth($payload, $this->signingKey);

        return self::base64UrlEncode($payload . $mac);
    }

    /**
     * Decode and verify a challenge token's signature, returning the challenge.
     *
     * Returns null on a malformed token or an invalid signature. Does NOT check
     * expiry — {@see verify()} owns the freshness window.
     */
    #[NoDiscard]
    public function parse(string $challengeToken): ?ManagedChallenge
    {
        $raw = self::base64UrlDecode($challengeToken);

        if ($raw === null || strlen($raw) !== self::PAYLOAD_BYTES + SODIUM_CRYPTO_AUTH_BYTES) {
            return null;
        }

        $payload = substr($raw, 0, self::PAYLOAD_BYTES);
        $mac = substr($raw, self::PAYLOAD_BYTES);

        if (!sodium_crypto_auth_verify($mac, $payload, $this->signingKey)) {
            return null;
        }

        /** @var array{1: int} $issuedAt */
        $issuedAt = unpack('J', substr($payload, 0, 8));
        /** @var array{1: int} $bits */
        $bits = unpack('N', substr($payload, 8, 4));

        return new ManagedChallenge(
            id: bin2hex(substr($payload, 12, 16)),
            bits: $bits[1],
            issuedAt: $issuedAt[1],
        );
    }

    /**
     * Verify a submitted `challengeToken.solution` token end to end.
     *
     * Signature → freshness → proof-of-work → single-use. Any failure returns
     * false without consuming the challenge; success records the challenge id
     * so the token cannot be replayed within its lifetime.
     */
    #[NoDiscard]
    public function verify(string $submittedToken): bool
    {
        $parts = explode('.', $submittedToken, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$challengeToken, $solution] = $parts;

        if ($solution === '' || strlen($solution) > self::MAX_SOLUTION_LENGTH) {
            return false;
        }

        $challenge = $this->parse($challengeToken);

        if ($challenge === null) {
            return false;
        }

        $now = time();
        $age = $now - $challenge->issuedAt;

        // Reject expired challenges and those minted in the future (clock skew
        // beyond a small tolerance signals a forged or replayed timestamp).
        if ($age > $this->ttlSeconds || $age < -5) {
            return false;
        }

        if (!self::meetsDifficulty($challenge->id, $solution, $challenge->bits)) {
            return false;
        }

        return $this->consumeSingleUse($challenge, $age);
    }

    /**
     * Difficulty (leading zero bits) the renderer advertises to the client.
     */
    #[NoDiscard]
    public function difficultyBits(): int
    {
        return $this->difficultyBits;
    }

    /**
     * Whether a key of the required length is present (managed challenge usable).
     */
    #[NoDiscard]
    public function hasValidKey(): bool
    {
        return strlen($this->signingKey) === SODIUM_CRYPTO_AUTH_KEYBYTES;
    }

    /**
     * Enforce single-use via the replay cache (best-effort without a cache).
     */
    private function consumeSingleUse(ManagedChallenge $challenge, int $age): bool
    {
        if ($this->cache === null) {
            $this->logger?->warning(
                'Managed challenge single-use enforcement disabled: no cache bound. '
                . 'A solved token may be replayed within its TTL.',
            );

            return true;
        }

        // Dot-separated: ':' is a PSR-6 reserved character the tagged cache
        // rejects. The challenge id is hex, so the key is otherwise safe.
        $key = 'antispam_managed_challenge.' . $challenge->id;

        if ($this->cache->get($key) !== null) {
            return false;
        }

        $this->cache->set($key, '1', [self::CACHE_TAG], max(1, $this->ttlSeconds - $age));

        return true;
    }

    /**
     * Whether SHA-256(id . '.' . solution) has at least $bits leading zero bits.
     */
    private static function meetsDifficulty(string $id, string $solution, int $bits): bool
    {
        if ($bits <= 0) {
            return true;
        }

        $digest = hash('sha256', $id . '.' . $solution, true);

        return self::leadingZeroBits($digest) >= $bits;
    }

    /**
     * Count leading zero bits across a binary string.
     */
    private static function leadingZeroBits(string $bytes): int
    {
        $count = 0;
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);

            if ($byte === 0) {
                $count += 8;

                continue;
            }

            for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                if (($byte & $mask) !== 0) {
                    return $count;
                }

                $count++;
            }

            return $count;
        }

        return $count;
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
