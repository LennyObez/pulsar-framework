<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_keys;
use function array_map;
use function class_exists;
use function extension_loaded;
use function function_exists;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
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
        'stampede_protection', 'gc_divisor', 'compression', 'compression_level',
        'compression_threshold_bytes', 'compression_length_oracle_acknowledged',
        'prefix', 'stampede_lock_ttl_seconds', 'stampede_lock_timeout_ms',
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
     *     compression?: string|false|null,
     *     compression_level?: int,
     *     compression_threshold_bytes?: int,
     *     compression_length_oracle_acknowledged?: bool,
     *     prefix?: string,
     *     stampede_lock_ttl_seconds?: int,
     *     stampede_lock_timeout_ms?: int,
     * } $data
     */
    private static function buildPoolConfig(string $name, array $data): CachePoolConfig
    {
        $driver = self::resolveDriver($name, $data['driver'] ?? 'filesystem');
        $encrypted = $data['encrypted'] ?? false;
        $compression = self::resolveCompression(
            $name,
            $data['compression'] ?? null,
            $encrypted,
            (bool) ($data['compression_length_oracle_acknowledged'] ?? false),
        );

        return new CachePoolConfig(
            name: $name,
            driver: $driver,
            serializer: self::resolveSerializer($name, $data['serializer'] ?? 'json'),
            defaultTtlSeconds: $data['default_ttl_seconds'] ?? null,
            critical: $data['critical'] ?? false,
            encrypted: $encrypted,
            tagsStrategy: $data['tags_strategy'] ?? 'auto',
            host: $data['host'] ?? null,
            port: $data['port'] ?? null,
            path: $data['path'] ?? null,
            allowedClasses: self::parseAllowedClasses($data['allowed_classes'] ?? null),
            stampedeProtection: $data['stampede_protection'] ?? true,
            gcDivisor: $data['gc_divisor'] ?? 100,
            compression: $compression,
            compressionLevel: self::resolveCompressionLevel($name, $data['compression_level'] ?? null, $compression),
            compressionThresholdBytes: $data['compression_threshold_bytes'] ?? 4096,
            compressionLengthOracleAcknowledged: (bool) ($data['compression_length_oracle_acknowledged'] ?? false),
            prefix: self::resolvePrefix($name, $data['prefix'] ?? ''),
            stampedeLockTtlSeconds: $data['stampede_lock_ttl_seconds'] ?? 30,
            stampedeLockTimeoutMs: $data['stampede_lock_timeout_ms'] ?? 5000,
        );
    }

    /**
     * Validate a pool's key prefix: a conservative charset (no PSR-6 reserved
     * characters beyond ':' and no Redis glob metacharacters, so SCAN MATCH
     * patterns stay literal) and a length cap that preserves key budget on
     * backends with hard limits (Memcached caps keys at 250 bytes; the
     * validator caps logical keys at 250, so the prefix shrinks the effective
     * budget by its length).
     *
     * @throws ConfigException If the prefix contains unsupported characters or
     *     is longer than 64 characters
     */
    private static function resolvePrefix(string $poolName, mixed $prefix): string
    {
        if ($prefix === '' || $prefix === null) {
            return '';
        }

        if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $prefix) !== 1) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.prefix",
                'prefix must match [A-Za-z0-9_.:-]{1,64} (no glob metacharacters, max 64 chars)',
            );
        }

        return $prefix;
    }

    /**
     * Validate a pool's compression setting: the algorithm must be known, its
     * extension loaded (fail-fast, like drivers and serializers), and the
     * compression+encryption combination must be an explicit, informed choice —
     * compress-then-encrypt leaks plaintext structure through ciphertext
     * length (CRIME-class oracle), which a regulated deployment must not
     * enable by accident.
     *
     * @throws ConfigException On an unknown algorithm, a missing extension, or
     *     unacknowledged compression of an encrypted pool
     */
    private static function resolveCompression(
        string $poolName,
        mixed $compression,
        bool $encrypted,
        bool $oracleAcknowledged,
    ): ?string {
        if ($compression === null || $compression === false) {
            return null;
        }

        if (!is_string($compression) || !in_array($compression, ['auto', 'zstd', 'zlib'], true)) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.compression",
                sprintf(
                    'unknown compression "%s"; valid values are: auto, zstd, zlib (or false)',
                    is_string($compression) ? $compression : get_debug_type($compression),
                ),
            );
        }

        $requiredFunction = match ($compression) {
            'zstd' => 'zstd_compress',
            'zlib' => 'gzcompress',
            default => null,
        };

        if ($requiredFunction !== null && !function_exists($requiredFunction)) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.compression",
                sprintf('compression "%s" requires ext-%s, which is not loaded', $compression, $compression),
            );
        }

        if ($encrypted && !$oracleAcknowledged) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.compression",
                'compressing an encrypted pool leaks plaintext structure through '
                . 'ciphertext length (CRIME-class oracle); set '
                . 'compression_length_oracle_acknowledged: true to accept that '
                . 'trade-off explicitly, or disable one of the two',
            );
        }

        return $compression;
    }

    /**
     * Validate a pool's compression level against the codec's own accepted
     * range, failing at boot rather than letting the codec reject (or silently
     * clamp) it on the first write.
     *
     * 'auto' is validated against zstd's range: it is the only codec 'auto' can
     * resolve to besides zlib, and zlib's range is a subset of it.
     */
    private static function resolveCompressionLevel(
        string $poolName,
        mixed $level,
        ?string $compression,
    ): ?int {
        if ($level === null) {
            return null;
        }

        if ($compression === null) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.compression_level",
                'compression_level was set but compression is disabled; enable '
                . 'compression or drop the level',
            );
        }

        if (!is_int($level)) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.compression_level",
                sprintf('compression_level must be an int, got %s', get_debug_type($level)),
            );
        }

        // zstd accepts 1-22; zlib accepts 0-9.
        [$min, $max] = $compression === 'zlib' ? [0, 9] : [1, 22];

        if ($level < $min || $level > $max) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.compression_level",
                sprintf(
                    'compression_level %d is out of range for "%s"; valid levels are %d-%d',
                    $level,
                    $compression,
                    $min,
                    $max,
                ),
            );
        }

        return $level;
    }

    /**
     * Validate a pool's serializer name, failing loudly on an unknown value or
     * a missing extension instead of silently substituting JSON. Before this
     * check, `serializer: 'igbinary'` (or any typo) quietly became the JSON
     * default: objects a pool was configured to round-trip came back as
     * arrays — the same config-says-one-thing-runtime-does-another hazard as
     * an unknown driver.
     *
     * @throws ConfigException If the serializer is unknown, or igbinary is
     *     configured but ext-igbinary is not loaded
     */
    private static function resolveSerializer(string $poolName, mixed $serializer): string
    {
        if (!is_string($serializer) || !in_array($serializer, ['json', 'php', 'igbinary'], true)) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.serializer",
                sprintf(
                    'unknown cache serializer "%s"; valid serializers are: json, php, igbinary',
                    is_string($serializer) ? $serializer : get_debug_type($serializer),
                ),
            );
        }

        if ($serializer === 'igbinary' && !extension_loaded('igbinary')) {
            throw ConfigException::invalidValue(
                "cache.pools.{$poolName}.serializer",
                'the igbinary serializer requires ext-igbinary, which is not loaded',
            );
        }

        return $serializer;
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
