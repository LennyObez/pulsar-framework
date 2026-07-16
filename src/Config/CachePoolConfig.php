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
    ) {}
}
