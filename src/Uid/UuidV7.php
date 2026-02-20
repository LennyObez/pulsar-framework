<?php

declare(strict_types=1);

namespace Pulsar\Uid;

use NoDiscard;
use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function chr;
use function ctype_xdigit;
use function hexdec;
use function microtime;
use function ord;
use function pack;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

/**
 * UUID version 7 (time-ordered, RFC 9562) generator and parser.
 *
 * UUID v7 layout (128 bits):
 *
 * ```
 *  0                   1                   2                   3
 *  0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |                            unix_ts_ms                         |
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |          unix_ts_ms           |  ver  |       rand_a          |
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |var|                        rand_b                             |
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |                            rand_b                             |
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * ```
 *
 * Why v7 instead of v4? IDs created in the same millisecond share a common
 * prefix, so a B-tree primary key on a v7 column produces sequential leaf
 * inserts (no random page splits). For OLTP workloads with millions of
 * INSERTs per day this halves write amplification compared to v4.
 *
 * The 62 bits of randomness still provide enough collision resistance for
 * any sane workload — the birthday bound is 2^31 IDs/ms.
 */
#[Api(since: '1.0.0')]
final class UuidV7
{
    private const string CANONICAL_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * Generate a new UUIDv7 in canonical hyphenated form.
     *
     * Example: `01957bd1-04ce-7a31-8f3c-12c5a82f7f1b`
     */
    #[NoDiscard]
    public static function generate(): string
    {
        return self::generateAt(self::nowMilliseconds());
    }

    /**
     * Generate a UUIDv7 with a caller-supplied unix-ms timestamp.
     *
     * Useful for tests that need deterministic values and for backfill
     * scripts that import historical records under their original
     * creation date.
     */
    #[NoDiscard]
    public static function generateAt(int $unixMilliseconds): string
    {
        if ($unixMilliseconds < 0 || $unixMilliseconds > 0xFFFFFFFFFFFF) {
            throw UidException::timestampOutOfRange($unixMilliseconds);
        }

        $randomizer = new Randomizer(new Secure());
        $randomBytes = $randomizer->getBytes(10);

        // Pack the 48-bit timestamp as a big-endian 6-byte field.
        // PHP's pack() does not support 48-bit ints directly, so split
        // into a 16-bit high half and a 32-bit low half.
        $tsHigh = ($unixMilliseconds >> 32) & 0xFFFF;
        $tsLow = $unixMilliseconds & 0xFFFFFFFF;
        $timestamp = pack('nN', $tsHigh, $tsLow);

        // Set the version (0111) and variant (10xx) bits per RFC 9562.
        $randomBytes[0] = chr((ord($randomBytes[0]) & 0x0F) | 0x70);
        $randomBytes[2] = chr((ord($randomBytes[2]) & 0x3F) | 0x80);

        $bytes = $timestamp . $randomBytes;

        return self::format($bytes);
    }

    /**
     * Extract the embedded unix-millisecond timestamp from a UUIDv7.
     *
     * @throws UidException If the value is not a valid UUIDv7.
     */
    #[NoDiscard]
    public static function extractTimestamp(string $uuid): int
    {
        if (!self::isValid($uuid)) {
            throw UidException::invalidUuidV7($uuid);
        }

        $hex = self::stripHyphens($uuid);

        // First 12 hex chars = 48 bits = unix_ts_ms. The 48-bit value
        // always fits in PHP_INT_MAX on 64-bit platforms (which Pulsar
        // requires); `hexdec()` returns `int|float` for the 32-bit
        // overflow case that does not apply here.
        return (int) hexdec(substr($hex, 0, 12));
    }

    /**
     * Validate a string as a canonical UUIDv7.
     *
     * Cheap pure-regex check; safe to call on any user input. Use this
     * before any other parse method that might throw on bad input.
     */
    #[NoDiscard]
    public static function isValid(string $uuid): bool
    {
        return preg_match(self::CANONICAL_PATTERN, $uuid) === 1;
    }

    /**
     * Parse a canonical UUIDv7 into its raw 16-byte binary form.
     *
     * @throws UidException If the value is not a valid UUIDv7.
     */
    #[NoDiscard]
    public static function parse(string $uuid): string
    {
        if (!self::isValid($uuid)) {
            throw UidException::invalidUuidV7($uuid);
        }

        $hex = self::stripHyphens($uuid);
        $bytes = '';

        for ($i = 0; $i < 32; $i += 2) {
            // 2 hex chars decode to a single byte (0-255), well within
            // both PHP int range and `chr()`'s expected `int<0, 255>`.
            $byte = (int) hexdec(substr($hex, $i, 2));
            $bytes .= chr($byte & 0xFF);
        }

        return $bytes;
    }

    private static function nowMilliseconds(): int
    {
        // hrtime(true) returns nanoseconds since an arbitrary monotonic
        // origin, so it cannot stand in for wall-clock unix-ms; use
        // microtime instead. The (int) cast truncates, but UUID v7
        // timestamps are unix-ms not unix-us, so this is correct.
        return (int) (microtime(true) * 1000);
    }

    private static function format(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw UidException::invalidByteLength(strlen($bytes));
        }

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private static function stripHyphens(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            throw UidException::invalidUuidV7($uuid);
        }

        return $hex;
    }
}
