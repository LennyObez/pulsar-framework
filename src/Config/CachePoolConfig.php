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
     *        (negotiates zstd > lz4 > zlib by loaded extension), or an explicit
     *        'zstd' | 'lz4' | 'zlib'.
     * @param int $compressionThresholdBytes Values shorter than this are
     *        stored uncompressed.
     * @param bool $compressionLengthOracleAcknowledged Compressing plaintext
     *        before encrypting it leaks information through ciphertext length
     *        (a CRIME-class oracle when attacker-influenced data shares a
     *        payload with secrets). Combining `compression` with `encrypted`
     *        on one pool therefore fails at boot unless this flag records an
     *        explicit, informed acceptance of that trade-off.
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
        public int $compressionThresholdBytes = 4096,
        public bool $compressionLengthOracleAcknowledged = false,
    ) {}
}
