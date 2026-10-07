<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use JsonException;
use Pulsar\Api\Api;
use Pulsar\Config\ConfigRepository;
use Pulsar\Routing\Route;
use Random\RandomException;
use ReflectionException;
use SodiumException;

#[Api(since: '1.0.0')]
interface FrameworkCacheInterface
{
    /**
     * Write the config, route and container caches, and the manifest over them.
     *
     * `$bindings` carries the `Router::model()` declarations that qualify the
     * route table — a `BindingScope::Root` on a nested child, a relation named
     * for a segment no PHP property can be called after. They belong to this
     * call and not to a later one because a cached-route boot skips the project
     * route files that make those calls, so an implementation that stores the
     * routes without them serves a route table whose scope declarations have
     * silently gone. Their default is `[]` — the honest answer for a caller
     * whose route table declares none, and the wrong one for a caller that has
     * them and omits them.
     *
     * @param list<Route> $routes
     * @param array<class-string, list<array{name: string, type: class-string}>> $containerHints
     * @param list<CachedBinding> $bindings
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
        array $bindings = [],
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
     * `bindings` is empty whenever `routes` is null: routes and declarations are
     * one stored payload, so a caller never holds one without the other.
     *
     * The key is OPTIONAL in the contract and required in spirit. This
     * interface is stable API stamped `since: 1.0.0` and `bindings` was added to
     * the shape afterwards, so an implementation written against the published
     * contract cannot supply it; declaring it mandatory would make every such
     * implementation fatal on boot, which is a breaking change to stable API
     * wearing the shape of a type annotation. An implementation that omits it
     * is not offering a smaller payload — it is declining to say what the route
     * table binds, and {@see \Pulsar\Core\Kernel} answers by not serving that
     * route table at all and booting from the route files instead. Supply the
     * key. Omitting it costs the whole route cache, not just the declarations.
     *
     * @return array{manifest: CacheManifest, config: ?ConfigRepository, routes: ?list<CachedRoute>, bindings?: list<CachedBinding>, containerHints: ?array<class-string, list<array{name: string, type: class-string}>>}|null
     *
     * @throws CacheException
     * @throws JsonException
     * @throws SodiumException
     */
    public function load(string $configPath): ?array;

    public function cachePath(): string;

    public function computeInvalidationKey(string $configPath): string;
}
