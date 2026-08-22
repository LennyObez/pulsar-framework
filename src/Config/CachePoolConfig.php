<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Per-pool configuration DTO.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CachePoolConfig
{
    /**
     * @param list<class-string>|null $allowedClasses Classes permitted when
     *        deserializing with the 'php' serializer. Null or empty allows no
     *        objects (fail-closed); the 'json' serializer ignores this.
     * @param ?string $compression Value compression: null (off), 'auto'
     *        (negotiates zstd > zlib by loaded extension), or an explicit
     *        'zstd' | 'zlib'.
     * @param ?int $compressionLevel Codec level; null uses the codec's own
     *        default (zstd 3, zlib 6). Range-checked per codec at boot (zstd
     *        1-22, zlib 0-9). Worth tuning per pool: a codec's ratio is not
     *        monotonic in its level, and the optimum depends on what the pool
     *        actually stores — measure against real payloads, not fixtures.
     * @param int $compressionThresholdBytes Values shorter than this are
     *        stored uncompressed.
     * @param bool $compressionLengthOracleAcknowledged Compressing plaintext
     *        before encrypting it leaks information through ciphertext length
     *        (a CRIME-class oracle when attacker-influenced data shares a
     *        payload with secrets). Combining `compression` with `encrypted`
     *        on one pool therefore fails at boot unless this flag records an
     *        explicit, informed acceptance of that trade-off.
     * @param int $stampedeLockTtlSeconds Auto-expiry of the per-key stampede
     *        regeneration lock — an upper bound on how long a crashed winner can
     *        block regeneration. Must exceed the worst-case render time.
     * @param int $stampedeLockTimeoutMs How long a losing caller waits for the
     *        stampede lock before falling back. The invariant is
     *        `stampede_lock_timeout_ms > p99 regeneration time` (so a loser
     *        picks up the winner's write rather than recomputing) and
     *        `stampede_lock_ttl_seconds > timeout + p99` (so the lock outlives a
     *        legitimate render).
     * @param string $prefix Per-pool key namespace on shared backends
     *        (Redis/Memcached store keys raw, so pools and applications on one
     *        server share a keyspace). Empty (default) keeps current
     *        behaviour. With a prefix, clear() becomes an exact prefix-scoped
     *        deletion on drivers that can enumerate keys, and fails loudly on
     *        drivers that cannot — never a silent server-wide flush.
     */
    public function __construct(
        public string $name,
        public CacheDriverType $driver = CacheDriverType::Filesystem,
        public string $serializer = 'json',
        public ?int $defaultTtlSeconds = null,
        public bool $critical = false,
        public bool $encrypted = false,
        public string $tagsStrategy = 'auto',
        public ?string $host = null,
        public ?int $port = null,
        public ?string $path = null,
        public ?array $allowedClasses = null,
        public bool $stampedeProtection = true,
        public int $gcDivisor = 100,
        public ?string $compression = null,
        public ?int $compressionLevel = null,
        public int $compressionThresholdBytes = 4096,
        public bool $compressionLengthOracleAcknowledged = false,
        public string $prefix = '',
        public int $stampedeLockTtlSeconds = 30,
        public int $stampedeLockTimeoutMs = 5000,
    ) {}
}
