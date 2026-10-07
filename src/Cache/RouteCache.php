<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Routing\Route;
use Random\RandomException;
use SodiumException;

use function count;
use function is_array;
use function is_string;

/**
 * Serializes the route table via {@see CachedRouteTable}; skips closures.
 *
 * The payload carries the binding declarations registered with
 * `Router::model()` as well as the routes, because a cached-route boot skips
 * the project route files that make those calls. {@see CachedRouteTable}
 * records why they travel together.
 */
#[Internal]
final class RouteCache
{
    public const string FILENAME = 'routes.cache.bin';

    public function __construct(
        private readonly CacheIntegrity $integrity,
    ) {}

    /**
     * Write the route table to a cache file.
     *
     * @param list<Route> $routes
     * @param list<CachedBinding> $bindings Declarations from `Router::model()`; `[]` states that there are none
     * @return array{cached: int, skipped: int, skippedRoutes: list<string>}
     *
     * @throws CacheException
     * @throws SodiumException
     * @throws RandomException If nonce generation fails during encryption
     */
    public function write(string $cachePath, array $routes, bool $encrypt, array $bindings): array
    {
        $compiled = $this->compile($routes, $bindings);

        $this->writeCompiled($cachePath, $compiled['serialized'], $encrypt);

        return [
            'cached' => $compiled['cached'],
            'skipped' => $compiled['skipped'],
            'skippedRoutes' => $compiled['skippedRoutes'],
        ];
    }

    /**
     * Serialize the route table without writing it.
     *
     * Split out from {@see write()} so {@see FrameworkCache} can derive the
     * deserialization allowlist from the exact bytes it is about to store.
     * Deriving it from the payload rather than from a namespace scan is what
     * keeps a cached class from coming back as `__PHP_Incomplete_Class` on the
     * next boot, and the scan is a regex over source files — a baseline, not a
     * proof.
     *
     * @param list<Route> $routes
     * @param list<CachedBinding> $bindings
     * @return array{serialized: string, cached: int, skipped: int, skippedRoutes: list<string>}
     */
    public function compile(array $routes, array $bindings): array
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

        return [
            'serialized' => serialize(new CachedRouteTable($cachedRoutes, $bindings)),
            'cached' => count($cachedRoutes),
            'skipped' => count($skippedRoutes),
            'skippedRoutes' => $skippedRoutes,
        ];
    }

    /**
     * Store a payload produced by {@see compile()}.
     *
     * @throws CacheException
     * @throws SodiumException
     * @throws RandomException If nonce generation fails during encryption
     */
    public function writeCompiled(string $cachePath, string $serialized, bool $encrypt): void
    {
        $this->integrity->writeEnvelope($cachePath . DIRECTORY_SEPARATOR . self::FILENAME, $serialized, $encrypt);
    }

    /**
     * Load the cached route table.
     *
     * Returns null for anything this build cannot vouch for, and a null answer
     * is a cold boot: the project route files run, `Router::model()` runs with
     * them, and the application is served the table it declares rather than a
     * half of one. Every rejection below is therefore a fallback, not a failure.
     *
     * The shape is checked element by element rather than trusted from a
     * docblock. `unserialize()` with an `allowed_classes` list does not reject a
     * disallowed class — it substitutes `__PHP_Incomplete_Class` — so a payload
     * whose classes fell outside the allowlist deserializes into an object graph
     * that satisfies every `array` type hint and fails much later, somewhere
     * that cannot fall back.
     *
     * @param list<class-string> $allowedClasses
     *
     * @throws CacheException
     * @throws SodiumException
     */
    public function load(string $cachePath, array $allowedClasses): ?CachedRouteTable
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        $result = $this->integrity->readEnvelope($path, $allowedClasses);

        if (!$result instanceof CachedRouteTable) {
            return null;
        }

        foreach ($result->routes as $route) {
            if (!$route instanceof CachedRoute) {
                return null;
            }
        }

        foreach ($result->bindings as $binding) {
            if (!$binding instanceof CachedBinding) {
                return null;
            }
        }

        return $result;
    }

    /**
     * Normalize a route handler to a RouteHandler or null if not cacheable.
     *
     * @param mixed $handler
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
