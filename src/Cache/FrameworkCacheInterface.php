<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use JsonException;
use Pulsar\Api\Api;
use Pulsar\Config\ConfigRepository;
use Random\RandomException;
use ReflectionException;
use SodiumException;

#[Api]
interface FrameworkCacheInterface
{
    /**
     * @param list<\Pulsar\Routing\Route> $routes
     * @param array<class-string, list<array{name: string, type: class-string}>> $containerHints
     *
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
    ): array;

    /**
     * @throws CacheException
     */
    public function clear(): void;

    /**
     * @throws JsonException
     * @throws SodiumException
     */
    public function isWarm(): bool;

    /**
     * @return array{manifest: CacheManifest, config: ?ConfigRepository, routes: ?list<CachedRoute>, containerHints: ?array<class-string, list<array{name: string, type: class-string}>>}|null
     *
     * @throws CacheException
     * @throws JsonException
     * @throws SodiumException
     */
    public function load(string $configPath): ?array;

    public function cachePath(): string;

    public function computeInvalidationKey(string $configPath): string;
}
