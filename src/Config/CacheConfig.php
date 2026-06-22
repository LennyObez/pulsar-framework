<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function class_exists;
use function is_array;
use function is_string;

/**
 * Typed configuration DTO for `config/cache.php`.
 * @api
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
     * @param array{
     *     enabled?: bool|int|string,
     *     default_pool?: string,
     *     path?: string,
     *     pools?: array<string, mixed>,
     * } $data Raw array from config/cache.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('CACHE_ENABLED') !== null
            ? $environment->get('CACHE_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $defaultPool = $environment->get('CACHE_DEFAULT_POOL') ?? $data['default_pool'] ?? 'default';

        $path = $environment->get('CACHE_PATH') ?? $data['path'] ?? 'var/cache';

        $poolsData = $data['pools'] ?? [];

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
     * @param array{
     *     driver?: string,
     *     serializer?: string,
     *     default_ttl_seconds?: int|null,
     *     critical?: bool,
     *     encrypted?: bool,
     *     tags_strategy?: string,
     *     host?: string|null,
     *     port?: int|null,
     *     path?: string|null,
     *     allowed_classes?: mixed,
     * } $data
     */
    private static function buildPoolConfig(string $name, array $data): CachePoolConfig
    {
        $driver = CacheDriverType::tryFrom($data['driver'] ?? 'filesystem') ?? CacheDriverType::Filesystem;

        return new CachePoolConfig(
            name: $name,
            driver: $driver,
            serializer: $data['serializer'] ?? 'json',
            defaultTtlSeconds: $data['default_ttl_seconds'] ?? null,
            critical: $data['critical'] ?? false,
            encrypted: $data['encrypted'] ?? false,
            tagsStrategy: $data['tags_strategy'] ?? 'auto',
            host: $data['host'] ?? null,
            port: $data['port'] ?? null,
            path: $data['path'] ?? null,
            allowedClasses: self::parseAllowedClasses($data['allowed_classes'] ?? null),
        );
    }

    /**
     * Normalize a configured PHP-serializer allowlist into loadable class names.
     * Non-existent entries are dropped so the allowlist can never widen
     * deserialization to a class that is not actually present.
     *
     * @return list<class-string>|null
     */
    private static function parseAllowedClasses(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $classes = [];

        /** @var mixed $class */
        foreach ($value as $class) {
            if (is_string($class) && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes === [] ? null : $classes;
    }
}
