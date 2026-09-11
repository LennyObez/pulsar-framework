<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Config\DomainConfig;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Binding\ExplicitBinding;

use function array_values;
use function count;
use function ksort;
use function sprintf;
use function str_contains;
use function strstr;
use function trim;

/**
 * HTTP router for route registration and matching.
 *
 * Registration, indexing, and matching are one cohesive responsibility (the
 * route table) and stay here — splitting them out adds a call frame on the
 * matching hot path for marginal benefit. The genuinely separable, non-hot
 * concerns are delegated: {@see RouteUrlGenerator} builds URLs and
 * {@see ResourceRegistrar} scaffolds RESTful resource route sets.
 * @api
 */
#[Api(since: '1.0.0')]
final class Router implements RouterInterface
{
    /**
     * @var list<Route>
     */
    public private(set) array $routes = [];

    /**
     * @var array<string, Route>
     */
    public private(set) array $namedRoutes = [];

    /**
     * Method-indexed lookup table for fast matching.
     *
     * O(1) hash map for static routes (no dynamic segments), indexed by HTTP
     * method then normalized path — the most common case.
     *
     * @var array<string, array<string, Route>>
     */
    private array $staticRoutes = [];

    /**
     * First-segment bucket index for dynamic routes.
     *
     * Maps `method => bucketKey => registrationSequence => Route` so the
     * dynamic-route scan for `/users/{id}` requests only walks routes whose
     * pattern begins with the `users` segment. The catch-all bucket `''` holds
     * every route whose first segment is not a literal a request path can
     * reproduce verbatim — `/{lang}/posts`, and equally `/u{user}/posts/{post}`.
     *
     * Bucket entries are keyed by the route's global registration sequence so the
     * first-segment and catch-all buckets can be merged back into registration
     * order at match time (first-registered-wins).
     *
     * @var array<string, array<string, array<int, Route>>>
     */
    private array $dynamicRouteBuckets = [];

    /**
     * Whether any registered route carries a host constraint.
     *
     * When no route is host-constrained, the request `Host` header cannot change
     * which route matches, so the O(1) static-route fast path stays safe even with
     * a Host header present (the common case). When at least one host-constrained
     * route exists, matching falls back to the host-aware candidate scan.
     */
    private bool $hasHostConstrainedRoutes = false;

    /**
     * Diagnostic records of routes shadowed by an earlier registration of the
     * same (method, path, host) key.
     *
     * Registration is first-registered-wins (framework > project > extension), so
     * a later route claiming an already-registered key is recorded here and
     * excluded from the match tables rather than silently overriding the winner.
     * Read by the boot-time {@see \Pulsar\Routing\RouteCollisionReporter}, which
     * warns in production and fails closed in debug mode.
     *
     * @var list<RouteCollision>
     */
    public private(set) array $collisions = [];

    /**
     * Registration-key index backing collision detection.
     *
     * Maps `method => normalizedPath => hostKey => winner Route`. The first route
     * registered for a key becomes the winner; any later route with a different
     * handler is a collision.
     *
     * @var array<string, array<string, array<string, Route>>>
     */
    private array $registeredRouteKeys = [];

    /**
     * Explicit parameter-to-model bindings registered via model().
     *
     * @var list<ExplicitBinding>
     */
    public private(set) array $explicitBindings = [];

    /**
     * Whether the router is locked (strict cache mode).
     *
     * When locked, {@see self::add()} accepts a registration identical to one
     * the cached table already holds and refuses every other with
     * {@see RoutingException::routerLocked()}. See
     * {@see self::isReplayOfCachedRoute()} for why the test is identity rather
     * than arrival.
     */
    public private(set) bool $locked = false;

    /**
     * Add a route to the router.
     *
     * @throws RoutingException If the router is locked and this route is not
     *                          already in the cached table
     */
    public function add(Route $route): self
    {
        if ($this->locked && $this->isReplayOfCachedRoute($route)) {
            return $this;
        }

        if ($this->locked) {
            throw RoutingException::routerLocked($route->path);
        }

        $this->routes[] = $route;

        if ($route->name !== null) {
            $this->namedRoutes[$route->name] = $route;
        }

        // The route's index in $this->routes is its global registration sequence.
        $this->indexRouteByMethod($route, count($this->routes) - 1);

        return $this;
    }

    /**
     * Whether a registration arriving at a locked router is one the cached
     * table already holds, field for field.
     *
     * A strict route cache is authoritative, and the lock exists to keep a route
     * out of the served table that was never written into the verified one. What
     * the lock does NOT get to refuse is the boot that produced the cache in the
     * first place: every wiring that registered a route at `pulsar optimize`
     * time registers it again on the next boot, and so does every extension. The
     * lock used to throw on all of them, so the first framework wiring to reach
     * it — `I18nWiring`, third in the boot order — aborted the boot, and a
     * deployment that ran `optimize --strict` could not start at all.
     *
     * So the question is identity, not arrival. A registration equal in every
     * field to one already indexed adds nothing to the table and is dropped; a
     * registration that differs anywhere is a route the cache does not vouch
     * for, and it still throws. `==` rather than `===` because the replay is a
     * different object built from the same declaration — the cached half was
     * rebuilt by {@see \Pulsar\Core\Boot\CachedRouteReconstructor} — and equality
     * of value is exactly the claim being tested. It covers handler, name,
     * middleware, constraints, host and attributes, so a route whose access
     * declaration or middleware changed since the cache was written is drift and
     * is reported as drift.
     *
     * Every method the route claims must resolve to that same route: a
     * registration that widens `[GET]` to `[GET, POST]` is not the cached route,
     * and half-matching it would serve a verb the cache never carried.
     */
    private function isReplayOfCachedRoute(Route $route): bool
    {
        if ($route->methods === []) {
            return false;
        }

        $normalizedPath = '/' . trim($route->path, '/');
        $hostKey = $route->host ?? '';

        foreach ($route->methods as $method) {
            $existing = $this->registeredRouteKeys[$method->value][$normalizedPath][$hostKey] ?? null;

            if ($existing === null || $existing != $route) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add a route to the method-indexed and static lookup tables.
     *
     * @param int $sequence The route's global registration order (its index in
     *                      {@see $routes}), used to key dynamic-route buckets.
     */
    private function indexRouteByMethod(Route $route, int $sequence): void
    {
        if ($route->host !== null) {
            $this->hasHostConstrainedRoutes = true;
        }

        // A static route has no dynamic segments and no host constraint, so it can
        // live in the O(1) static table; everything else is bucketed by its first
        // literal segment: `/users/{id}` buckets under `users`, `/{lang}/x` and
        // `/u{user}/x` under `''` (catch-all), keyed by registration sequence.
        $isStatic = $route->compiledPattern === null && $route->host === null;
        $normalizedPath = '/' . trim($route->path, '/');
        $hostKey = $route->host ?? '';
        $firstSegment = $isStatic ? '' : $this->routeBucketKey($route->path);

        foreach ($route->methods as $method) {
            $existing = $this->registeredRouteKeys[$method->value][$normalizedPath][$hostKey] ?? null;

            if ($existing !== null) {
                // Benign duplicate: the SAME route re-registered. A non-strict route
                // cache replays every route (including extension routes) and the
                // extension then boots again and re-registers identical routes;
                // matching handler + name means it is the same route, not a
                // conflict, so ignore it silently.
                if ($existing->handler == $route->handler && $existing->name === $route->name) {
                    continue;
                }

                // Genuine conflict: a different handler claims an already-registered
                // key. First-registered wins (framework > project > extension), so
                // the earlier route stays in the match tables and the later one is
                // recorded for the boot-time reporter rather than silently shadowing.
                $this->collisions[] = new RouteCollision(
                    $method->value,
                    $normalizedPath,
                    $route->host,
                    $existing,
                    $route,
                );
                continue;
            }

            $this->registeredRouteKeys[$method->value][$normalizedPath][$hostKey] = $route;

            if ($isStatic) {
                $this->staticRoutes[$method->value][$normalizedPath] = $route;
            } else {
                $this->dynamicRouteBuckets[$method->value][$firstSegment][$sequence] = $route;
            }
        }
    }

    /**
     * The bucket a route pattern is indexed under.
     *
     * `/users/{id}` → `users`, `/api/v1/users/{id}` → `api`, `/{lang}/posts` →
     * `''`, `/u{user}/posts/{post}` → `''`, `/` → `''`.
     *
     * The bucket is a hash lookup against {@see requestFirstSegment()}, so a
     * route may only claim a key that some request path's first segment can
     * equal VERBATIM. A segment holding a placeholder never can: `u{user}` is
     * matched by `u1`, `u2`, `uanything`, and by the literal string `u{user}`
     * alone out of all of them. Indexing such a route under its own pattern
     * text put it in a bucket no request could reach — the route stopped
     * routing entirely, and the cold 405 scan then found it by regex and
     * answered `405` with the requested method in its own `Allow` header.
     *
     * So the rule is stricter than "does not begin with `{`": the whole segment
     * must be free of placeholders, or the route goes to the catch-all bucket
     * and is scanned on every dynamic request. That gives up the partition for
     * partial-segment routes, which is the price of them being matchable at
     * all; no prefix key can serve a hash bucket, because the request side
     * would have to try every prefix length to find it.
     */
    private function routeBucketKey(string $path): string
    {
        $first = $this->requestFirstSegment($path);

        return str_contains($first, '{') ? '' : $first;
    }

    /**
     * The first path segment of a request, verbatim. `/users/1` → `users`,
     * `/u1/posts/20` → `u1`, `/` → `''`.
     *
     * No placeholder handling here, deliberately: this reads a concrete URL,
     * where `{` is an ordinary character. The placeholder rule belongs to
     * {@see routeBucketKey()}, which reads a pattern.
     */
    private function requestFirstSegment(string $path): string
    {
        $normalized = trim($path, '/');
        if ($normalized === '') {
            return '';
        }

        $first = strstr($normalized, '/', true);

        return $first === false ? $normalized : $first;
    }

    /**
     * Add multiple routes from a group.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function addGroup(RouteGroup $group): self
    {
        foreach ($group->flatten() as $route) {
            $this->add($route);
        }

        return $this;
    }

    /**
     * Register a GET route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function get(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::get($path, $handler, $name));
    }

    /**
     * Register a POST route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::post($path, $handler, $name));
    }

    /**
     * Register a PUT route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::put($path, $handler, $name));
    }

    /**
     * Register a PATCH route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function patch(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::patch($path, $handler, $name));
    }

    /**
     * Register a DELETE route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::delete($path, $handler, $name));
    }

    /**
     * Register a route matching any method.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function any(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::any($path, $handler, $name));
    }

    /**
     * Register a group of routes under a common prefix.
     *
     * Creates a temporary router, passes it to the callback, then
     * adds all registered routes with the prefix prepended to their paths.
     *
     * @param string   $prefix   The URL prefix for all routes in the group.
     * @param callable $callback Receives a RouterInterface to register grouped routes.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function group(string $prefix, callable $callback): self
    {
        $prefix = '/' . trim($prefix, '/');
        $subRouter = new self();

        $callback($subRouter);

        foreach ($subRouter->routes as $route) {
            $prefixedPath = $prefix . '/' . trim($route->path, '/');

            $this->add(new Route(
                methods: $route->methods,
                path: $prefixedPath,
                handler: $route->handler,
                name: $route->name,
                attributes: $route->attributes,
                middleware: $route->middleware,
                constraints: $route->constraints,
                host: $route->host,
            ));
        }

        return $this;
    }

    /**
     * Match a request path and method to a route.
     *
     * Uses a method-indexed lookup table on the hot path so that only
     * routes accepting the requested HTTP method are scanned. On a miss,
     * falls back to scanning all routes for method-not-allowed detection
     * (cold path).
     *
     * @param Method $method The HTTP method
     * @param string $path The request path
     * @param string|null $host The request Host header (for host-based routing)
     * @throws RoutingException When no route matches or method is not allowed
     */
    public function match(Method $method, string $path, ?string $host = null): MatchedRoute
    {
        $normalizedPath = '/' . trim($path, '/');

        // Strip the port (and IPv6 brackets' port) so a `Host: api.example.com:8000`
        // header matches a route declared against `api.example.com`.
        $host = $host === null ? null : HostNormalizer::stripPort($host);

        $staticRoute = $this->staticRoutes[$method->value][$normalizedPath] ?? null;

        // Fast path: O(1) lookup for static routes. Safe when the request carries
        // no host, OR when no route is host-constrained (a Host header then cannot
        // change which route matches) — so real traffic still hits the hash map.
        if ($staticRoute !== null && ($host === null || !$this->hasHostConstrainedRoutes)) {
            return new MatchedRoute($staticRoute, []);
        }

        // Narrow the dynamic-route scan to the first-segment bucket of the
        // request path + the catch-all bucket (every pattern whose first segment
        // is not a literal — `/{lang}/x`, `/u{user}/x`), merged back into
        // registration order via their sequence keys so an earlier catch-all
        // wins over a later literal-first-segment overlap.
        $requestFirstSegment = $this->requestFirstSegment($normalizedPath);
        $methodBuckets = $this->dynamicRouteBuckets[$method->value] ?? [];

        $candidates = $methodBuckets[$requestFirstSegment] ?? [];
        if ($requestFirstSegment !== '' && isset($methodBuckets[''])) {
            $candidates += $methodBuckets[''];
            ksort($candidates);
        }

        /** @var list<Route> $candidates */
        $candidates = array_values($candidates);

        // Reaching here with a static route in hand means the fast path was
        // skipped for the one reason it can be: the request carries a Host and
        // some route — anywhere in the table, on any path — is host-constrained.
        // That fact must not decide which handler owns THIS path. Splicing the
        // static route in by precedence rather than appending it keeps the
        // ordering the fast path applies:
        //
        //   1. a route constrained to a host matching this request's Host — the
        //      most specific claim, and the reason the scan runs at all;
        //   2. the host-less static route — a literal path beats a placeholder,
        //      exactly as the O(1) table beats the bucket scan;
        //   3. host-less dynamic routes, first-registered-wins (ADR-0034).
        //
        // Appending it to the end put a later-registered `/users/{id}` ahead of
        // an earlier `/users/profile` for a request carrying a Host header, and
        // nowhere else — so a client-supplied header, plus one unrelated
        // host-constrained route, chose the handler. Route attributes carry
        // authorization metadata, so that is an access-control decision.
        if ($staticRoute !== null) {
            $hostConstrained = [];
            $hostLess = [];

            foreach ($candidates as $candidate) {
                if ($candidate->host !== null) {
                    $hostConstrained[] = $candidate;
                } else {
                    $hostLess[] = $candidate;
                }
            }

            $candidates = [...$hostConstrained, $staticRoute, ...$hostLess];
        }

        foreach ($candidates as $route) {
            $matchResult = $this->matchRouteAgainstHostAndPath($route, $path, $host);
            if ($matchResult !== null) {
                return new MatchedRoute($route, $matchResult);
            }
        }

        // Cold path: no match found: scan all routes for 405 detection
        $pathMatches = [];

        foreach ($this->routes as $route) {
            $matchResult = $this->matchRouteAgainstHostAndPath($route, $path, $host);
            if ($matchResult !== null) {
                $pathMatches[] = $route;
            }
        }

        if ($pathMatches !== []) {
            $allowedMethodsMap = [];

            foreach ($pathMatches as $route) {
                foreach ($route->methods as $m) {
                    $allowedMethodsMap[$m->value] = $m;
                }
            }

            // The scan above ignores the method, so a route reaching here that
            // DOES accept the requested method is one the indexed pass should
            // have returned and did not. Answering 405 for it is a lie the
            // client can read off the response — the rejected method is listed
            // in the very `Allow` header the refusal carries — and it is how a
            // partial-segment route indexed under a bucket key no request could
            // produce stayed hidden: unroutable, reported as a method problem.
            //
            // Serving the route from here instead would be worse than the lie.
            // This scan walks every registered route, including the ones
            // deliberately excluded from the match tables as collisions, so a
            // shadowed route would start being served by the fallback that was
            // meant to describe a miss. The index and the route table have
            // disagreed; say so, and fail closed.
            if (isset($allowedMethodsMap[$method->value])) {
                throw RoutingException::routeIndexInconsistent($path, $method, $pathMatches[0]->path);
            }

            throw RoutingException::methodNotAllowed($path, $method, array_values($allowedMethodsMap));
        }

        throw RoutingException::notFound($path);
    }

    /**
     * Check if a route matches the given host and path.
     *
     * Returns merged host+path parameters on match, null on no match.
     *
     * @return array<string, string>|null
     */
    private function matchRouteAgainstHostAndPath(Route $route, string $path, ?string $host): ?array
    {
        if ($host !== null) {
            $hostParams = $route->matchesHost($host);
            if ($hostParams === null) {
                return null;
            }
        } else {
            $hostParams = [];
            if ($route->host !== null) {
                return null;
            }
        }

        $params = $route->matchesPath($path);
        if ($params === null) {
            return null;
        }

        return [...$hostParams, ...$params];
    }

    /**
     * Get a route by name.
     */
    public function getByName(string $name): ?Route
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * Generate a URL for a named route.
     *
     * When a DomainConfig is provided and the route's attributes include
     * a 'scope' that is mapped to a subdomain, generates a fully-qualified
     * URL with the correct subdomain (e.g., 'https://forum.example.com/threads/1').
     *
     * Without domain config, returns a relative path as before.
     *
     * @param array<string, string> $parameters
     * @throws InvalidArgumentException If route not found
     */
    public function url(string $name, array $parameters = [], ?DomainConfig $domainConfig = null): string
    {
        $route = $this->getByName($name)
            ?? throw new InvalidArgumentException(sprintf('Route "%s" not found', $name));

        return RouteUrlGenerator::generate($route, $parameters, $domainConfig);
    }

    /**
     * Get all registered routes.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Get the number of registered routes.
     */
    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * Lock the router (strict cache mode).
     *
     * Called once, by the kernel, after a strict route cache has been loaded —
     * so the table is already populated when the lock closes, and a later
     * registration can be compared against it. Registering a route the table
     * does not already hold throws {@see RoutingException::routerLocked()}.
     */
    public function lock(): void
    {
        $this->locked = true;
    }

    /**
     * Capture the full router state for the kernel boot/shutdown lifecycle.
     *
     * @internal Intended for {@see \Pulsar\Core\Kernel} reboot handling only:
     *           the kernel snapshots state at boot() entry and restores it on
     *           shutdown() so a subsequent boot does not accumulate duplicate
     *           routes/bindings. Not for application use.
     *
     * @return array{
     *     routes: list<Route>,
     *     namedRoutes: array<string, Route>,
     *     staticRoutes: array<string, array<string, Route>>,
     *     dynamicRouteBuckets: array<string, array<string, array<int, Route>>>,
     *     registeredRouteKeys: array<string, array<string, array<string, Route>>>,
     *     collisions: list<RouteCollision>,
     *     explicitBindings: list<ExplicitBinding>,
     *     locked: bool,
     *     hasHostConstrainedRoutes: bool,
     * }
     */
    public function snapshot(): array
    {
        return [
            'routes' => $this->routes,
            'namedRoutes' => $this->namedRoutes,
            'staticRoutes' => $this->staticRoutes,
            'dynamicRouteBuckets' => $this->dynamicRouteBuckets,
            'registeredRouteKeys' => $this->registeredRouteKeys,
            'collisions' => $this->collisions,
            'explicitBindings' => $this->explicitBindings,
            'locked' => $this->locked,
            'hasHostConstrainedRoutes' => $this->hasHostConstrainedRoutes,
        ];
    }

    /**
     * Restore router state from a {@see snapshot()}.
     *
     * @internal Intended for {@see \Pulsar\Core\Kernel} reboot handling only.
     *
     * @param array{
     *     routes: list<Route>,
     *     namedRoutes: array<string, Route>,
     *     staticRoutes: array<string, array<string, Route>>,
     *     dynamicRouteBuckets: array<string, array<string, array<int, Route>>>,
     *     registeredRouteKeys: array<string, array<string, array<string, Route>>>,
     *     collisions: list<RouteCollision>,
     *     explicitBindings: list<ExplicitBinding>,
     *     locked: bool,
     *     hasHostConstrainedRoutes: bool,
     * } $snapshot
     */
    public function restoreFromSnapshot(array $snapshot): void
    {
        $this->routes = $snapshot['routes'];
        $this->namedRoutes = $snapshot['namedRoutes'];
        $this->staticRoutes = $snapshot['staticRoutes'];
        $this->dynamicRouteBuckets = $snapshot['dynamicRouteBuckets'];
        $this->registeredRouteKeys = $snapshot['registeredRouteKeys'];
        $this->collisions = $snapshot['collisions'];
        $this->explicitBindings = $snapshot['explicitBindings'];
        $this->locked = $snapshot['locked'];
        $this->hasHostConstrainedRoutes = $snapshot['hasHostConstrainedRoutes'];
    }

    /**
     * Register an explicit parameter-to-model binding.
     *
     * When the model binding middleware resolves route parameters, explicit
     * bindings take precedence over implicit type-hint resolution.
     *
     * `$scope` is the one place an application overrides what the route path
     * says about containment, and the default overrides nothing. Declaring
     * {@see BindingScope::Root} makes a parameter resolve globally even inside
     * a nested path — nothing then checks that the resource belongs to what
     * precedes it in the URL, on every route carrying that parameter name, not
     * just the one you had in mind. Declaring {@see BindingScope::Contained}
     * names the relation for a segment that cannot, such as `blog-posts`.
     *
     * @param class-string $modelClass
     * @param class-string|null $resolverClass
     * @param string|null $parentRelation Relation to resolve through; required by, and exclusive to, {@see BindingScope::Contained}
     */
    public function model(
        string $parameter,
        string $modelClass,
        ?string $resolverClass = null,
        BindingScope $scope = BindingScope::Path,
        ?string $parentRelation = null,
    ): self {
        $this->explicitBindings[] = new ExplicitBinding(
            $parameter,
            $modelClass,
            $resolverClass,
            $scope,
            $parentRelation,
        );

        return $this;
    }

    /**
     * Register a full resource route set (7 routes).
     *
     * Generates: index, create, store, show, edit, update, destroy.
     *
     * @param string $name Resource name (e.g. 'photos'): used for URL prefix and route names
     * @param string $controller Controller class or handler prefix (e.g. 'App\Controller\PhotoController')
     * @param list<string> $middleware Middleware applied to all resource routes
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function resource(string $name, string $controller, array $middleware = []): self
    {
        foreach (ResourceRegistrar::resourceRoutes($name, $controller, $middleware) as $route) {
            $this->add($route);
        }

        return $this;
    }

    /**
     * Register an API resource route set (5 routes, no create/edit forms).
     *
     * Generates: index, store, show, update, destroy.
     *
     * @param string $name Resource name (e.g. 'photos'): used for URL prefix and route names
     * @param string $controller Controller class or handler prefix
     * @param list<string> $middleware Middleware applied to all resource routes
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function apiResource(string $name, string $controller, array $middleware = []): self
    {
        foreach (ResourceRegistrar::apiResourceRoutes($name, $controller, $middleware) as $route) {
            $this->add($route);
        }

        return $this;
    }

    /**
     * Load pre-built routes (e.g. reconstructed from cache by the composition root).
     *
     * @param list<Route> $routes
     */
    public function loadRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            $this->routes[] = $route;

            if ($route->name !== null) {
                $this->namedRoutes[$route->name] = $route;
            }

            $this->indexRouteByMethod($route, count($this->routes) - 1);
        }
    }

    /**
     * Load pre-built binding declarations alongside {@see loadRoutes()}.
     *
     * The counterpart of {@see model()} for a boot that reads its route table
     * from a cache instead of building it. `Router::model()` runs from the
     * project route files, and a cached-route boot does not read those files —
     * so without this the one channel an application has for declaring
     * {@see BindingScope::Root} or naming a relation would exist in development
     * and not in the deployment it was written for.
     *
     * Separate from {@see model()} rather than a loop over it, for the same
     * reason {@see loadRoutes()} is separate from {@see add()}: this is not
     * registration, it is restoration of a registration that already happened,
     * and it must stay usable while the composition root is locking the router
     * down for strict cache mode.
     *
     * @internal Intended for the composition root's cached-boot path.
     *
     * @param list<ExplicitBinding> $bindings
     */
    public function loadBindings(array $bindings): void
    {
        foreach ($bindings as $binding) {
            $this->explicitBindings[] = $binding;
        }
    }
}
