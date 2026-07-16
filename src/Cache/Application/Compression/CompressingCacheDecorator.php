<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Compression;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Exception\CacheException;

use function function_exists;
use function gzcompress;
use function gzuncompress;
use function lz4_compress;
use function lz4_uncompress;
use function str_starts_with;
use function strlen;
use function substr;
use function zstd_compress;
use function zstd_uncompress;

/**
 * Transparent value compression for any cache driver (opt-in per pool).
 *
 * Sits between the serializer output and the storage backend (when the pool is
 * also encrypted, compression wraps the ENCRYPTING decorator so plaintext is
 * compressed before encryption — ciphertext is incompressible). Values at or
 * above the configured threshold are compressed with the negotiated algorithm;
 * smaller or incompressible values are stored as-is.
 *
 * Stored format is self-describing: compressed (and escape-wrapped) values
 * carry a four-byte magic prefix plus one algorithm byte, so compressed and
 * raw entries coexist in one pool. Enabling, disabling, or switching the
 * algorithm never invalidates existing entries: reads fall back to raw
 * passthrough for anything without the magic. The magic starts with 0xF0,
 * which no serializer output can begin with (JSON is printable, PHP serialize
 * starts alphanumeric, igbinary starts 0x00, the encryption envelope is JSON) —
 * and a raw value that DOES begin with the magic is escape-wrapped in an
 * uncompressed envelope on write, so the encoding stays injective.
 *
 * Counters bypass compression entirely: increment()/decrement() delegate
 * untransformed (backends do server-side arithmetic on plain integers), and
 * their plain values never match the magic on read.
 */
#[Internal]
final readonly class CompressingCacheDecorator implements CacheDriverInterface
{
    /** Envelope prefix; see the class docblock for why 0xF0 leads. */
    private const string MAGIC = "\xF0PC1";

    /** Algorithm byte: stored uncompressed (escape wrapper). */
    private const string ALGO_NONE = "\x00";

    /** Algorithm byte: zlib (RFC 1950, gzcompress). */
    private const string ALGO_ZLIB = "\x01";

    /** Algorithm byte: zstd. */
    private const string ALGO_ZSTD = "\x02";

    /** Algorithm byte: lz4. */
    private const string ALGO_LZ4 = "\x03";

    /**
     * @param CacheDriverInterface $inner Next driver in the stack
     * @param string $algorithm 'zstd', 'lz4', or 'zlib' — already negotiated
     *     and extension-checked by configuration ('auto' resolves before this
     *     class is built)
     * @param int $thresholdBytes Values shorter than this are stored raw
     */
    public function __construct(
        private CacheDriverInterface $inner,
        private string $algorithm,
        private int $thresholdBytes = 4096,
    ) {}

    public function get(string $key): ?string
    {
        $raw = $this->inner->get($key);

        if ($raw === null) {
            return null;
        }

        return $this->decode($raw);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string|null>
     */
    public function getMultiple(array $keys): array
    {
        $result = [];

        foreach ($this->inner->getMultiple($keys) as $key => $raw) {
            $result[$key] = $raw === null ? null : $this->decode($raw);
        }

        return $result;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        return $this->inner->set($key, $this->encode($value), $ttlSeconds);
    }

    public function add(string $key, string $value, ?int $ttlSeconds): bool
    {
        // Encode first, then delegate: the conditional store still happens
        // once, on the encoded payload, preserving the inner add()'s atomicity.
        return $this->inner->add($key, $this->encode($value), $ttlSeconds);
    }

    /**
     * @param array<string, string> $values
     */
    public function setMultiple(array $values, ?int $ttlSeconds): bool
    {
        $encoded = [];

        foreach ($values as $key => $value) {
            $encoded[$key] = $this->encode($value);
        }

        return $this->inner->setMultiple($encoded, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    /**
     * @param list<string> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function increment(string $key, int $step = 1): int|false
    {
        return $this->inner->increment($key, $step);
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->inner->decrement($key, $step);
    }

    public function capabilities(): CacheDriverCapabilities
    {
        return $this->inner->capabilities();
    }

    public function name(): string
    {
        return 'compressed:' . $this->inner->name();
    }

    private function encode(string $value): string
    {
        if (strlen($value) < $this->thresholdBytes) {
            return $this->escapeIfNeeded($value);
        }

        $compressed = $this->compress($value);

        // Incompressible payloads (already-compressed media, high-entropy
        // blobs) would only grow with the envelope — store them raw instead.
        if ($compressed === false || strlen($compressed) + 5 >= strlen($value)) {
            return $this->escapeIfNeeded($value);
        }

        return self::MAGIC . $this->algorithmByte() . $compressed;
    }

    private function decode(string $raw): string
    {
        if (!str_starts_with($raw, self::MAGIC)) {
            // Legacy or below-threshold entry stored raw: pass through.
            return $raw;
        }

        $algo = substr($raw, 4, 1);
        $payload = substr($raw, 5);

        $decoded = match ($algo) {
            self::ALGO_NONE => $payload,
            self::ALGO_ZLIB => function_exists('gzuncompress') ? gzuncompress($payload) : false,
            self::ALGO_ZSTD => function_exists('zstd_uncompress') ? zstd_uncompress($payload) : false,
            self::ALGO_LZ4 => function_exists('lz4_uncompress') ? lz4_uncompress($payload) : false,
            default => false,
        };

        if ($decoded === false) {
            throw CacheException::serializationFailed(
                'Failed to decompress a cached value; the entry is corrupt or the '
                . 'compression extension that wrote it is no longer loaded.',
            );
        }

        return $decoded;
    }

    /**
     * A raw value that happens to begin with the envelope magic would misparse
     * on read; wrap it in an uncompressed envelope so decoding stays injective.
     */
    private function escapeIfNeeded(string $value): string
    {
        if (str_starts_with($value, self::MAGIC)) {
            return self::MAGIC . self::ALGO_NONE . $value;
        }

        return $value;
    }

    private function compress(string $value): string|false
    {
        return match ($this->algorithm) {
            'zstd' => function_exists('zstd_compress') ? zstd_compress($value) : false,
            'lz4' => function_exists('lz4_compress') ? lz4_compress($value) : false,
            default => gzcompress($value),
        };
    }

    private function algorithmByte(): string
    {
        return match ($this->algorithm) {
            'zstd' => self::ALGO_ZSTD,
            'lz4' => self::ALGO_LZ4,
            default => self::ALGO_ZLIB,
        };
    }
}
