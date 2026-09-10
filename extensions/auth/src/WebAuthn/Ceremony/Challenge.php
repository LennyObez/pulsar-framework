<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Ceremony;

use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Internal;
use Random\RandomException;

use function abs;
use function base64_decode;
use function base64_encode;
use function chr;
use function is_int;
use function ord;
use function pack;
use function random_bytes;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;
use function substr;
use function unpack;

/**
 * Encoding for WebAuthn ceremony challenges that carries its own issuance instant.
 *
 * A challenge is only useful if it is *fresh*: `challenge_ttl_seconds` is the
 * window in which the relying party is willing to accept a response. Enforcing
 * that window needs the issuance instant at verification time, and the ceremony
 * API is deliberately stateless — `generateOptions()` hands the challenge back to
 * the caller, which stores it (session, signed cookie, cache) and returns it to
 * `verify()`. There is no server-side challenge table to read a timestamp from.
 *
 * So the instant travels inside the challenge itself. Every caller and every
 * storage mechanism already round-trips the challenge string verbatim, which
 * means the TTL rides along for free and — more importantly — cannot be
 * forgotten or opted out of by a caller. A TTL that a caller can skip is not a
 * control.
 *
 * Layout (32 bytes, base64url without padding):
 *
 *   byte  0      format version (1)
 *   bytes 1..7   issuance instant, seconds since the Unix epoch, big-endian
 *   bytes 8..31  24 cryptographically random bytes
 *
 * 24 random bytes is 192 bits of entropy, well above the 16 bytes (128 bits) the
 * WebAuthn Level 2 specification requires of a challenge (§13.4.3), so binding
 * the timestamp in costs no meaningful unpredictability.
 *
 * The structure is deliberately *not* authenticated with a MAC. The instant is
 * only ever read from the relying party's own copy of the challenge — the value
 * `verify()` receives as `$expectedChallenge`, which the server minted and
 * stored. The copy echoed by the client is never parsed; it is only compared
 * against the server copy with `hash_equals`. An attacker who could rewrite the
 * server's stored challenge already controls the session that the ceremony
 * authenticates, so a MAC would defend nothing that is not already lost, at the
 * cost of a key dependency in a class that has none.
 *
 * A value this decoder cannot date is treated as not fresh rather than as
 * unlimited: a challenge this server did not mint has no established issuance
 * instant, and accepting it would be a bypass of the window.
 *
 * Freshness alone was never single use. This encoding answers "when was it
 * issued", which bounds the replay window; it cannot answer "has it been
 * answered", which closes it. That second question is
 * {@see \Pulsar\Extension\Auth\WebAuthn\Contract\ChallengeStoreInterface}'s,
 * and it is the one piece of the challenge lifecycle that genuinely needs state.
 * Keeping the two apart is deliberate: the instant stays inside the value, where
 * no caller can drop it, and only spent-ness — which cannot be carried by an
 * immutable value at all — goes to a store.
 */
#[Internal(reason: 'WebAuthn challenge encoding used by the ceremony implementations')]
final readonly class Challenge
{
    /** Format marker in byte 0, so a value this server did not mint is recognisable. */
    public const int FORMAT_VERSION = 1;

    /** Bytes of randomness after the version byte and the timestamp. */
    public const int RANDOM_BYTES = 24;

    /** Bytes carrying the big-endian issuance instant. */
    private const int TIMESTAMP_BYTES = 7;

    /** Total decoded length: version byte + timestamp + randomness. */
    private const int ENCODED_BYTES = 1 + self::TIMESTAMP_BYTES + self::RANDOM_BYTES;

    /**
     * Mint a challenge stamped with its issuance instant.
     *
     * @throws RandomException When the platform CSPRNG is unavailable; a
     *                         predictable challenge is worse than no ceremony.
     */
    public static function issue(DateTimeImmutable $issuedAt): string
    {
        // pack('J') is a big-endian unsigned 64-bit value; the leading byte is
        // dropped because seven bytes already cover instants far beyond any
        // deployment lifetime and keep the challenge at exactly 32 bytes.
        $timestamp = substr(pack('J', $issuedAt->getTimestamp()), 1);

        return self::base64UrlEncode(
            chr(self::FORMAT_VERSION) . $timestamp . random_bytes(self::RANDOM_BYTES),
        );
    }

    /**
     * Read the issuance instant out of a challenge this server minted.
     *
     * Returns null when the value is not in this format, which is the same
     * answer as "this server never issued it".
     */
    public static function issuedAt(string $encoded): ?DateTimeImmutable
    {
        $raw = self::base64UrlDecode($encoded);

        if (strlen($raw) !== self::ENCODED_BYTES || ord($raw[0]) !== self::FORMAT_VERSION) {
            return null;
        }

        /** @var array{seconds?: int}|false $unpacked */
        $unpacked = unpack('Jseconds', "\x00" . substr($raw, 1, self::TIMESTAMP_BYTES));
        $seconds = $unpacked === false ? null : ($unpacked['seconds'] ?? null);

        if (!is_int($seconds) || $seconds <= 0) {
            return null;
        }

        return new DateTimeImmutable('@' . $seconds)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Whether the challenge was minted by this server and is still inside its window.
     *
     * The comparison is symmetric: a challenge dated more than the TTL into the
     * *future* is refused as well. A future instant cannot come from this
     * server's clock, so it means either an unusable clock or a value that only
     * happens to look like a challenge — treating it as "not yet expired" would
     * hand an unbounded lifetime to exactly the values that deserve none.
     *
     * `challenge_ttl_seconds: 0` therefore admits only a ceremony answered inside
     * the same clock second, and a negative value admits nothing at all. Both are
     * read as "no window", not as "no limit".
     */
    public static function isFresh(string $encoded, int $ttlSeconds, DateTimeImmutable $now): bool
    {
        $issuedAt = self::issuedAt($encoded);

        if ($issuedAt === null) {
            return false;
        }

        return abs($now->getTimestamp() - $issuedAt->getTimestamp()) <= $ttlSeconds;
    }

    /**
     * The instant past which {@see isFresh()} refuses this challenge.
     *
     * Handed to {@see \Pulsar\Extension\Auth\WebAuthn\Contract\ChallengeStoreInterface}
     * so a store can expire its record of a spent challenge in step with the
     * window, rather than holding every challenge the server ever issued.
     * Returns null for a value this server did not mint, which has no issuance
     * instant and therefore no expiry.
     *
     * Only the forward edge is reported. {@see isFresh()} is symmetric and also
     * refuses a challenge dated more than the TTL into the FUTURE, but that arm
     * exists to reject values this server's clock cannot have produced; it is not
     * a lifetime, and a store has nothing to schedule against it.
     */
    public static function expiresAt(string $encoded, int $ttlSeconds): ?DateTimeImmutable
    {
        $issuedAt = self::issuedAt($encoded);

        if ($issuedAt === null) {
            return null;
        }

        return new DateTimeImmutable('@' . ($issuedAt->getTimestamp() + $ttlSeconds))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);

        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded !== false ? $decoded : '';
    }
}
