<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Closure;

use function count;
use function is_array;
use function is_string;

use Pulsar\Api\Internal;
use Pulsar\Routing\Route;
use Random\RandomException;
use SodiumException;

/**
 * Serializes routes via CachedRoute DTOs; skips closures.
 */
#[Internal]
final class RouteCache
{
    public const string FILENAME = 'routes.cache.bin';

    public function __construct(
        private readonly CacheIntegrity $integrity,
    ) {}

    /**
     * Write routes to a cache file.
     *
     * @param list<Route> $routes
     * @return array{cached: int, skipped: int, skippedRoutes: list<string>}
     *
     * @throws CacheException
     * @throws SodiumException
     * @throws RandomException If nonce generation fails during encryption
     */
    public function write(string $cachePath, array $routes, bool $encrypt): array
    {
        $cachedRoutes = [];
        $skippedRoutes = [];

        foreach ($routes as $route) {
            $handler = $this->normalizeHandler($route->handler);

            if ($handler === null) {
                $skippedRoutes[] = $route->path;
                continue;
            }

            $cachedRoutes[] = new CachedRoute(
                methods: $route->methods,
                path: $route->path,
                handler: $handler,
                name: $route->name,
                attributes: $route->attributes,
                middleware: $route->middleware,
                constraints: $route->constraints,
                host: $route->host,
            );
        }

        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;
        $serialized = serialize($cachedRoutes);
        $this->integrity->writeEnvelope($path, $serialized, $encrypt);

        return [
            'cached' => count($cachedRoutes),
            'skipped' => count($skippedRoutes),
            'skippedRoutes' => $skippedRoutes,
        ];
    }

    /**
     * Load cached routes from a cache file.
     *
     * @param list<class-string> $allowedClasses
     * @return list<CachedRoute>|null
     *
     * @throws CacheException
     * @throws SodiumException
     */
    public function load(string $cachePath, array $allowedClasses): ?array
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        $result = $this->integrity->readEnvelope($path, $allowedClasses);

        if (!is_array($result)) {
            return null;
        }

        /** @var list<CachedRoute> $result */
        return $result;
    }

    /**
     * Normalize a route handler to a RouteHandler or null if not cacheable.
     *
     * @param callable|class-string|list<string> $handler
     */
    private function normalizeHandler(mixed $handler): ?RouteHandler
    {
        if ($handler instanceof Closure) {
            return null;
        }

        if (is_string($handler)) {
            return new RouteHandler(
                type: RouteHandlerType::Invokable,
                resolvable: $handler,
            );
        }

        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            return new RouteHandler(
                type: RouteHandlerType::Method,
                resolvable: $handler[0],
                method: $handler[1],
            );
        }

        return null;
    }
}
