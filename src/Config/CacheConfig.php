<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_keys;
use function array_map;
use function class_exists;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Typed configuration DTO for `config/cache.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CacheConfig
{
    /**
     * Top-level keys recognised in config/cache.php.
     *
     * @var list<string>
     */
    private const array KNOWN_KEYS = ['enabled', 'default_pool', 'path', 'pools'];

    /**
     * Per-pool keys recognised under `pools.<name>`.
     *
     * @var list<string>
     */
    private const array KNOWN_POOL_KEYS = [
        'driver', 'serializer', 'default_ttl_seconds', 'critical', 'encrypted',
        'tags_strategy', 'host', 'port', 'path', 'allowed_classes',
        'stampede_protection', 'gc_divisor',
    ];

    /**
     * @param string $defaultPool Default pool name
     * @param string $path Default filesystem cache path
     * @param array<string, CachePoolConfig> $pools Named pool configurations
     * @param list<string> $unknownKeys Configuration keys that were present but
     *        not recognised (dotted paths, e.g. `cache.pools.default.tlt`). A
     *        typo silently ignored is a config that does not do what it says, so
     *        the wiring logs these at boot rather than letting them vanish.
     */
    public function __construct(
        public bool $enabled = false,
        public string $defaultPool = 'default',
        public string $path = 'var/cache',
        public array $pools = [],
        public array $unknownKeys = [],
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     default_pool?: string,
     *     path?: string,
     *     pools?: array<string, mixed>,
     * } $data Raw array from config/cache.php
     *
     * @throws ConfigException If a pool declares an unknown cache driver
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

        $unknownKeys = self::collectUnknownKeys($data, $poolsData);

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
            unknownKeys: $unknownKeys,
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
     *     stampede_protection?: bool,
     *     gc_divisor?: int,
     * } $data
     */
    private static function buildPoolConfig(string $name, array $data): CachePoolConfig
    {
        $driver = self::resolveDriver($name, $data['driver'] ?? 'filesystem');

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
            stampedeProtection: $data['stampede_protection'] ?? true,
            gcDivisor: $data['gc_divisor'] ?? 100,
        );
    }

    /**
     * Collect configuration keys that are present but not recognised, as dotted
     * paths, so the wiring can warn about them at boot. A silently-ignored typo
     * (`tlt` for `ttl`, `page_cach_ttl`) is a config that does not behave as the
     * operator intended — for a compliance-sensitive cache that must surface.
     *
     * @param array<string, mixed> $data
     * @param mixed $poolsData The raw `pools` value (validated per entry).
     *
     * @return list<string>
     */
    private static function collectUnknownKeys(array $data, mixed $poolsData): array
    {
        $unknown = [];

        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                $unknown[] = 'cache.' . $key;
            }
        }

        if (is_array($poolsData)) {
            /** @var mixed $poolData */
            foreach ($poolsData as $poolName => $poolData) {
                if (!is_array($poolData)) {
                    continue;
                }

                foreach (array_keys($poolData) as $poolKey) {
                    if (!in_array($poolKey, self::KNOWN_POOL_KEYS, true)) {
                        $unknown[] = "cache.pools.{$poolName}.{$poolKey}";
                    }
                }
            }
        }

        return $unknown;
    }

    /**
     * Resolve a configured driver name to its enum case, failing loudly on an
     * unknown value instead of silently falling back to filesystem. A typo like
     * `redys` must surface at boot — a regulated deployment that believes it is
     * caching in Redis (shared, evictable) while it is actually writing to the
     * local filesystem is a correctness and compliance hazard, not a convenience.
     *
     * @throws ConfigException If the driver is not one of the supported types
     */
    private static function resolveDriver(string $poolName, mixed $driver): CacheDriverType
    {
        $resolved = is_string($driver) ? CacheDriverType::tryFrom($driver) : null;

        if ($resolved === null) {
            $valid = implode(', ', array_map(
                static fn(CacheDriverType $case): string => $case->value,
                CacheDriverType::cases(),
            ));

            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.driver",
                sprintf(
                    'unknown cache driver "%s"; valid drivers are: %s',
                    is_string($driver) ? $driver : get_debug_type($driver),
                    $valid,
                ),
            );
        }

        return $resolved;
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
