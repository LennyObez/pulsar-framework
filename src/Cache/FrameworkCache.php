<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\Environment;
use Pulsar\Core\Version;
use Pulsar\Routing\Route;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Support\ProjectSourceRoots;
use Random\RandomException;
use ReflectionException;
use SodiumException;

use function file_get_contents;
use function glob;
use function hash;
use function hash_equals;
use function implode;
use function is_dir;
use function is_file;
use function serialize;
use function sort;

use const DIRECTORY_SEPARATOR;

/**
 * Framework cache orchestrator.
 *
 * Coordinates config, route, and container caching with HMAC-signed
 * manifests and deterministic invalidation.
 */
#[Internal]
final class FrameworkCache implements FrameworkCacheInterface
{
    /** MasterKey sub-key ID for cache HMAC. */
    private const int HMAC_SUB_KEY_ID = 7;

    /** MasterKey KDF context for cache HMAC. */
    private const string HMAC_CONTEXT = 'fw_cache';

    /** Manifest schema version (single source of truth: CacheManifest). */
    private const int SCHEMA_VERSION = CacheManifest::SCHEMA_VERSION;

    /** Environment variables that participate in cache invalidation. */
    private const array ENV_INVALIDATION_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'APP_KEY',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'PULSAR_MASTER_KEY',
        'CACHE_ENCRYPT',
        'SESSION_DRIVER',
        'SESSION_LIFETIME',
    ];

    private readonly string $cachePath;
    private readonly HmacInterface $hmac;
    private readonly string $hmacKey;
    private readonly CacheIntegrity $integrity;
    private readonly ConfigCache $configCache;
    private readonly RouteCache $routeCache;
    private readonly ContainerCache $containerCache;

    /** @throws SodiumException */
    public function __construct(
        private readonly string $basePath,
        KeyProviderInterface $masterKey,
        HmacInterface $hmac,
        private readonly bool $encrypt = false,
        ?EncryptorInterface $encryptor = null,
        private readonly ?Environment $environment = null,
    ) {
        $this->hmac = $hmac;
        $this->cachePath = $basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'framework';

        $this->hmacKey = $masterKey->deriveSubKey(self::HMAC_SUB_KEY_ID, self::HMAC_CONTEXT);

        $this->integrity = new CacheIntegrity($this->hmac, $this->hmacKey, $this->encrypt ? $encryptor : null);
        $this->configCache = new ConfigCache($this->integrity);
        $this->routeCache = new RouteCache($this->integrity);
        $this->containerCache = new ContainerCache($this->integrity);
    }

    /**
     * Write all caches and the manifest.
     *
     * @param list<Route> $routes
     * @param array<class-string, list<array{name: string, type: class-string}>> $containerHints
     * @return array{configCached: bool, routesCached: int, routesSkipped: int, skippedRoutes: list<string>, containerCached: bool}
     *
     * @throws CacheException
     * @throws JsonException
     * @throws RandomException
     * @throws ReflectionException
     * @throws SodiumException
     */
    public function warm(
        ConfigRepository $repository,
        array $routes,
        array $containerHints,
        string $appEnv,
        bool $strict,
    ): array {
        $lock = new CacheLock($this->cachePath);
        $lock->acquire();

        try {
            return $this->doWarm($repository, $routes, $containerHints, $appEnv, $strict);
        } finally {
            $lock->release();
        }
    }

    /**
     * Clear all cache files.
     *
     * @throws CacheException
     */
    public function clear(): void
    {
        $lock = new CacheLock($this->cachePath);
        $lock->acquire();

        try {
            $files = [
                $this->cachePath . DIRECTORY_SEPARATOR . ConfigCache::FILENAME,
                $this->cachePath . DIRECTORY_SEPARATOR . RouteCache::FILENAME,
                $this->cachePath . DIRECTORY_SEPARATOR . ContainerCache::FILENAME,
                $this->cachePath . DIRECTORY_SEPARATOR . 'manifest.json',
                $this->cachePath . DIRECTORY_SEPARATOR . 'allowed_classes.json',
            ];

            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Check if a warm cache exists and is valid.
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function isWarm(): bool
    {
        return CacheManifest::load($this->hmac, $this->cachePath, $this->hmacKey) !== null;
    }

    /**
     * Load caches if the manifest is valid and the invalidation key matches.
     *
     * @return array{manifest: CacheManifest, config: ?ConfigRepository, routes: ?list<CachedRoute>, containerHints: ?array<class-string, list<array{name: string, type: class-string}>>}|null
     *
     * @throws CacheException
     * @throws JsonException
     * @throws SodiumException
     */
    public function load(string $configPath): ?array
    {
        if (!is_dir($this->cachePath)) {
            return null;
        }

        try {
            $this->integrity->validateDirectory($this->cachePath);
        } catch (CacheException) {
            return null;
        }

        $manifest = CacheManifest::load($this->hmac, $this->cachePath, $this->hmacKey);

        if ($manifest === null) {
            return null;
        }

        // Verify invalidation key
        $currentKey = $this->computeInvalidationKey($configPath);

        if (!hash_equals($manifest->invalidationKey, $currentKey)) {
            return null;
        }

        // Verify allowed classes
        $allowedClasses = CacheAllowedClasses::load($this->cachePath);

        if ($allowedClasses === null) {
            return null;
        }

        $classesJson = file_get_contents($this->cachePath . DIRECTORY_SEPARATOR . 'allowed_classes.json');

        if ($classesJson === false) {
            return null;
        }

        $classesHash = hash('sha256', $classesJson);

        if (!hash_equals($manifest->allowedClassesHash, $classesHash)) {
            return null;
        }

        // Verify each cache file against the per-file SHA-256 + HMAC recorded
        // in the manifest. The manifest HMAC only proves the manifest text is
        // authentic — it does NOT prove the cache binaries themselves are
        // intact. Re-reading and verifying each file here closes the
        // file-replacement gap (an attacker swapping config.cache.bin while
        // leaving the manifest untouched).
        $cacheFiles = [
            'config' => $this->cachePath . DIRECTORY_SEPARATOR . ConfigCache::FILENAME,
            'routes' => $this->cachePath . DIRECTORY_SEPARATOR . RouteCache::FILENAME,
            'container' => $this->cachePath . DIRECTORY_SEPARATOR . ContainerCache::FILENAME,
        ];

        foreach ($cacheFiles as $name => $file) {
            $signature = $manifest->caches[$name] ?? null;

            if ($signature === null) {
                return null;
            }

            $content = file_get_contents($file);

            if ($content === false) {
                return null;
            }

            if (!$this->integrity->verify($content, $signature['sha256'], $signature['hmac'])) {
                return null;
            }
        }

        // Load individual caches
        $config = $this->configCache->load($this->cachePath, $allowedClasses);
        $routes = $this->routeCache->load($this->cachePath, $allowedClasses);
        $containerHints = $this->containerCache->load($this->cachePath, $allowedClasses);

        return [
            'manifest' => $manifest,
            'config' => $config,
            'routes' => $routes,
            'containerHints' => $containerHints,
        ];
    }

    /**
     * Get the cache directory path.
     */
    public function cachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Compute the deterministic invalidation key from current filesystem + env state.
     */
    public function computeInvalidationKey(string $configPath): string
    {
        $parts = [Version::full()];

        // Hash each config file
        $configFiles = glob($configPath . DIRECTORY_SEPARATOR . '*.php');

        if ($configFiles !== false) {
            sort($configFiles);
            foreach ($configFiles as $file) {
                $content = file_get_contents($file);
                if ($content !== false) {
                    $parts[] = hash('sha256', $content);
                }
            }
        }

        // Hash composer.lock if present
        $composerLock = $this->basePath . DIRECTORY_SEPARATOR . 'composer.lock';
        if (is_file($composerLock)) {
            $content = file_get_contents($composerLock);
            if ($content !== false) {
                $parts[] = hash('sha256', $content);
            }
        }

        // Hash structural env vars. Resolve through the Environment repository
        // (OS env + .env) when available so a key provided only in .env still
        // participates in invalidation; fall back to getenv() only when no
        // Environment was injected (e.g. the dev bootstrap).
        $envParts = [];
        foreach (self::ENV_INVALIDATION_KEYS as $key) {
            if ($this->environment !== null) {
                $value = $this->environment->get($key);
            } else {
                $osValue = getenv($key);
                $value = $osValue === false ? null : $osValue;
            }
            $envParts[] = $key . '=' . ($value ?? '');
        }
        $parts[] = hash('sha256', implode(':', $envParts));

        // Hash .env file if present (local/dev)
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            $content = file_get_contents($envFile);
            if ($content !== false) {
                $parts[] = hash('sha256', $content);
            }
        }

        return hash('sha256', implode(':', $parts));
    }

    /**
     * @param list<Route> $routes
     * @param array<class-string, list<array{name: string, type: class-string}>> $containerHints
     * @return array{configCached: bool, routesCached: int, routesSkipped: int, skippedRoutes: list<string>, containerCached: bool}
     *
     * @throws CacheException
     * @throws JsonException
     * @throws RandomException
     * @throws ReflectionException
     * @throws SodiumException
     */
    private function doWarm(
        ConfigRepository $repository,
        array $routes,
        array $containerHints,
        string $appEnv,
        bool $strict,
    ): array {
        // Build the deserialization allowlist. The namespace scan cannot see
        // config value objects in feature namespaces (Api, Database, Mail,
        // Tenancy, View, ...), so derive the exact config-graph classes from the
        // repository being cached and union them in; routes and container hints
        // stay covered by the scan's Routing/Cache scope. serialize($repository)
        // here matches exactly what ConfigCache::write() serializes.
        $vendorPath = $this->basePath . DIRECTORY_SEPARATOR . 'vendor';
        // Source roots come from the project's composer PSR-4 map, never an
        // assumed `src/`: a project mapping "App\\": "app/" has no src/ directory,
        // which previously failed the warm with a directory-open error. The
        // framework's own src/ is resolved by CacheAllowedClasses itself.
        $srcPaths = ProjectSourceRoots::discover($this->basePath);
        $allowedClasses = CacheAllowedClasses::forCache($vendorPath, $srcPaths, serialize($repository));
        CacheAllowedClasses::save($this->cachePath, $allowedClasses);

        // Compute allowed classes hash
        $classesJson = file_get_contents($this->cachePath . DIRECTORY_SEPARATOR . 'allowed_classes.json');
        $allowedClassesHash = hash('sha256', $classesJson !== false ? $classesJson : '');

        // Write config cache
        $this->configCache->write($this->cachePath, $repository, $this->encrypt);
        $configSig = $this->integrity->sign(
            file_get_contents($this->cachePath . DIRECTORY_SEPARATOR . ConfigCache::FILENAME) ?: '',
        );

        // Write route cache
        $routeResult = $this->routeCache->write($this->cachePath, $routes, $this->encrypt);
        $routeSig = $this->integrity->sign(
            file_get_contents($this->cachePath . DIRECTORY_SEPARATOR . RouteCache::FILENAME) ?: '',
        );

        // Write container cache
        $this->containerCache->write($this->cachePath, $containerHints, $this->encrypt);
        $containerSig = $this->integrity->sign(
            file_get_contents($this->cachePath . DIRECTORY_SEPARATOR . ContainerCache::FILENAME) ?: '',
        );

        // Compute invalidation key
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $invalidationKey = $this->computeInvalidationKey($configPath);

        // Write manifest
        CacheManifest::write(
            hmac: $this->hmac,
            cachePath: $this->cachePath,
            hmacKey: $this->hmacKey,
            schemaVersion: self::SCHEMA_VERSION,
            frameworkVersion: Version::full(),
            appEnv: $appEnv,
            invalidationKey: $invalidationKey,
            allowedClassesHash: $allowedClassesHash,
            caches: [
                'config' => $configSig,
                'routes' => $routeSig,
                'container' => $containerSig,
            ],
            strict: $strict,
            encrypted: $this->encrypt,
        );

        return [
            'configCached' => true,
            'routesCached' => $routeResult['cached'],
            'routesSkipped' => $routeResult['skipped'],
            'skippedRoutes' => $routeResult['skippedRoutes'],
            'containerCached' => true,
        ];
    }
}
