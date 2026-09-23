<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;

use function fread;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function pack;
use function strlen;
use function substr;
use function unpack;

use const JSON_THROW_ON_ERROR;

/**
 * The on-disk shape of a sealed backup archive, in one place.
 *
 * ```
 *  0   magic          8 bytes   "PULSARBK"
 *  8   format         1 byte    currently 1
 *  9   header length  4 bytes   big-endian uint32
 * 13   header         N bytes   JSON, PLAINTEXT, and authenticated as AAD
 *      seal header   24 bytes   crypto_secretstream_xchacha20poly1305 header
 *      chunks        repeated   4-byte big-endian length, then that many sealed bytes
 * ```
 *
 * WHY THE HEADER IS IN THE CLEAR. A restore has to answer two questions before it
 * can decrypt anything: is this a Pulsar archive, and was it sealed under a key
 * this host holds. Encrypting the answers would mean the only diagnosis available
 * for "wrong key" is "decryption failed", which is the same message a corrupted
 * archive produces — and telling those apart at 3am is the difference between
 * fetching the right key and declaring the copy lost. So the header carries the
 * format, the creation instant, the archive key id and the chunk size, and
 * nothing else: no entry names, no sizes, no source ids, because those describe
 * the estate and belong inside the seal.
 *
 * THE HEADER IS AUTHENTICATED EVEN THOUGH IT IS NOT ENCRYPTED. It is passed as
 * the additional data of every `push`/`pull`, so changing one byte of it makes
 * every chunk fail authentication. Without that, an attacker could rewrite the
 * recorded creation instant — the field an assessor reads to decide whether the
 * archive predates an incident — while leaving a perfectly valid seal underneath.
 *
 * THE SEALED PAYLOAD is a flat frame stream, not a container index, because an
 * index has to be written after the content it describes and would force either a
 * second pass over the archive or the whole thing in memory. Frames are:
 *
 *  - `ENTRY_BEGIN`  4-byte name length, then the name
 *  - `ENTRY_CHUNK`  4-byte length, then that many content bytes
 *  - `ENTRY_END`    32-byte BLAKE2b digest of the entry content, 8-byte byte count
 *  - `ARCHIVE_END`  4-byte entry count
 *
 * A reader that reaches the end of the seal without `ARCHIVE_END` is looking at a
 * truncated archive, and the AEAD's own final tag says the same thing one level
 * down; both are checked, because the two catch different truncations — a stream
 * cut mid-chunk fails the tag, and a stream cut exactly on a chunk boundary
 * during a copy would not.
 */
#[Internal(reason: 'The archive byte layout; producers and readers go through SealedArchiveBackupService')]
final readonly class ArchiveFormat
{
    public const string MAGIC = 'PULSARBK';

    public const int VERSION = 1;

    /** Plaintext bytes per sealed chunk: the memory bound of both writing and reading. */
    public const int CHUNK_SIZE = 65_536;

    /** Refuse a header claiming a length no legitimate archive has, before allocating it. */
    public const int MAX_HEADER_BYTES = 8_192;

    /**
     * Refuse a sealed chunk longer than one plaintext chunk plus its tag, before
     * allocating it. A corrupted length prefix is otherwise an allocation of up to
     * 4 GiB decided by the file being read.
     */
    public const int MAX_SEALED_CHUNK_BYTES = self::CHUNK_SIZE + 1_024;

    public const int FRAME_ENTRY_BEGIN = 1;
    public const int FRAME_ENTRY_CHUNK = 2;
    public const int FRAME_ENTRY_END = 3;
    public const int FRAME_ARCHIVE_END = 4;

    /** Length of the digest recorded in an ENTRY_END frame. */
    public const int DIGEST_BYTES = 32;

    /**
     * Encode the plaintext header.
     *
     * @param non-empty-string $keyId
     *
     * @throws JsonException
     */
    #[NoDiscard]
    public static function header(string $keyId, string $createdAt): string
    {
        return json_encode(
            [
                'format' => self::VERSION,
                'cipher' => 'crypto_secretstream_xchacha20poly1305',
                'chunk_size' => self::CHUNK_SIZE,
                'key_id' => $keyId,
                'created_at' => $createdAt,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Decode a header, returning null when it is not one this release understands.
     *
     * @return array{format: int, key_id: string, created_at: string}|null
     */
    #[NoDiscard]
    public static function parseHeader(string $json): ?array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $format = $decoded['format'] ?? null;
        $keyId = $decoded['key_id'] ?? null;
        $createdAt = $decoded['created_at'] ?? null;

        if (!is_int($format) || !is_string($keyId) || !is_string($createdAt) || $keyId === '') {
            return null;
        }

        return ['format' => $format, 'key_id' => $keyId, 'created_at' => $createdAt];
    }

    /** A 4-byte big-endian length prefix. */
    #[NoDiscard]
    public static function uint32(int $value): string
    {
        return pack('N', $value);
    }

    /** A 8-byte big-endian counter. */
    #[NoDiscard]
    public static function uint64(int $value): string
    {
        return pack('J', $value);
    }

    /**
     * Read a big-endian uint32 out of $bytes at $offset, or null if it is not there.
     */
    #[NoDiscard]
    public static function readUint32(string $bytes, int $offset): ?int
    {
        if (strlen($bytes) < $offset + 4) {
            return null;
        }

        /** @var array{1: int}|false $unpacked */
        $unpacked = unpack('N', substr($bytes, $offset, 4));

        return $unpacked === false ? null : $unpacked[1];
    }

    /**
     * Read a big-endian uint64 out of $bytes at $offset, or null if it is not there.
     *
     * Entry lengths are recorded in 64 bits rather than 32 because a single
     * database table dumped as newline-delimited JSON passes 4 GiB on estates this
     * framework is built for, and a length field that silently wrapped there would
     * make the entry digest check fail for a reason no message could explain.
     */
    #[NoDiscard]
    public static function readUint64(string $bytes, int $offset): ?int
    {
        if (strlen($bytes) < $offset + 8) {
            return null;
        }

        /** @var array{1: int}|false $unpacked */
        $unpacked = unpack('J', substr($bytes, $offset, 8));

        return $unpacked === false ? null : $unpacked[1];
    }

    /**
     * Read exactly $length bytes from a stream, or null when the stream ends first.
     *
     * `fread()` is allowed to return short reads on any stream, and a backup
     * archive is precisely the file most likely to be read off a network mount, so
     * every fixed-width field goes through this rather than through a bare
     * `fread($handle, $n)`.
     *
     * The remaining count is computed and CHECKED on every pass rather than being
     * inferred from the loop condition, so a caller that asks for nothing gets an
     * empty string and a caller that asks for a negative length gets an empty
     * string too, instead of `fread()` being handed a length it refuses.
     *
     * @param resource $handle
     */
    #[NoDiscard]
    public static function readExactly($handle, int $length): ?string
    {
        $collected = '';

        while (true) {
            $remaining = $length - strlen($collected);

            if ($remaining <= 0) {
                return $collected;
            }

            $piece = fread($handle, $remaining);

            if ($piece === false || $piece === '') {
                return null;
            }

            $collected .= $piece;
        }
    }
}
