<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Typed configuration DTO for `config/cache.php`.
 */
#[Api(since: '1.0.0')]
final readonly class CacheConfig
{
    /**
     * @param string $defaultPool Default pool name
     * @param string $path Default filesystem cache path
     * @param array<string, CachePoolConfig> $pools Named pool configurations
     */
    public function __construct(
        public bool $enabled = false,
        public string $defaultPool = 'default',
        public string $path = 'var/cache',
        public array $pools = [],
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/cache.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('CACHE_ENABLED') !== null
            ? $environment->get('CACHE_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $defaultPoolRaw = $data['default_pool'] ?? null;
        $defaultPool = $environment->get('CACHE_DEFAULT_POOL')
            ?? (is_string($defaultPoolRaw) ? $defaultPoolRaw : 'default');

        $path = $environment->get('CACHE_PATH')
            ?? (is_string($data['path'] ?? null) ? $data['path'] : 'var/cache');

        /** @var array<string, mixed> $poolsData */
        $poolsData = is_array($data['pools'] ?? null) ? $data['pools'] : [];

        /** @var array<string, CachePoolConfig> $pools */
        $pools = [];

        foreach ($poolsData as $poolName => $poolData) {
            if (!is_array($poolData)) {
                continue;
            }

            /** @var array<string, mixed> $poolData */
            $pools[$poolName] = self::buildPoolConfig($poolName, $poolData);
        }

        // Ensure default pool exists
        if (!isset($pools[$defaultPool])) {
            $pools[$defaultPool] = new CachePoolConfig(name: $defaultPool);
        }

        return new self(
            enabled: $enabled,
            defaultPool: $defaultPool,
            path: $path,
            pools: $pools,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function buildPoolConfig(string $name, array $data): CachePoolConfig
    {
        $driverValue = is_string($data['driver'] ?? null) ? $data['driver'] : 'filesystem';
        $driver = CacheDriverType::tryFrom($driverValue) ?? CacheDriverType::Filesystem;

        $rawTtl = $data['default_ttl_seconds'] ?? null;

        return new CachePoolConfig(
            name: $name,
            driver: $driver,
            serializer: is_string($data['serializer'] ?? null) ? $data['serializer'] : 'json',
            defaultTtlSeconds: is_int($rawTtl) ? $rawTtl : null,
            critical: is_bool($data['critical'] ?? null) ? $data['critical'] : false,
            encrypted: is_bool($data['encrypted'] ?? null) ? $data['encrypted'] : false,
            tagsStrategy: is_string($data['tags_strategy'] ?? null) ? $data['tags_strategy'] : 'auto',
            host: is_string($data['host'] ?? null) ? $data['host'] : null,
            port: is_int($data['port'] ?? null) ? $data['port'] : null,
            path: is_string($data['path'] ?? null) ? $data['path'] : null,
        );
    }
}
