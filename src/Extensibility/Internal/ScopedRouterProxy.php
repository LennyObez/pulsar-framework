<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Override;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;

use function implode;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Capability-gated proxy for RouterInterface.
 *
 * Enforces route namespace constraints based on trust tier:
 * - Core: full access, no proxy needed
 * - Verified: no prefix, but wildcards rejected
 * - Community: routes prefixed with /ext/{extension-name}/
 * - Untrusted: route registration denied
 *
 * ## One door
 *
 * Every registering method on this class funnels through {@see self::add()}.
 * That is structural rather than tidy. The previous shape had each verb apply
 * the prefix for itself, and the round that added {@see self::resource()} and
 * {@see self::apiResource()} asserted the capability in both and then forwarded
 * the resource name to the inner router UNPREFIXED — re-opening, in the same
 * change that reported it closed, the route takeover it had closed everywhere
 * else. A method that does not register through `add()` is a method that skipped
 * the confinement, and `SandboxSurfaceTest` enumerates this class by reflection
 * to say so.
 *
 * ## What `add()` confines
 *
 *  - The PATH, for Community and Untrusted ({@see self::prefixPath()}).
 *  - The NAME, for the same tiers ({@see self::prefixName()}). A route name is
 *    a global key: `Router::add()` writes `$namedRoutes[$name]` last-wins, so an
 *    extension registering a route called `login` re-pointed every
 *    `route('login')` in the host's templates at its own path while its path
 *    stayed dutifully inside `/ext/`. The prefix confined the path and not the
 *    name.
 *  - The HANDLER and the route MIDDLEWARE, which are class names the framework
 *    constructs at request time using the container it has to hand — the real
 *    one. {@see ScopedContainerProxy::provideThroughScope()} binds them to a
 *    factory that builds them through this extension's scope instead, so a
 *    controller declaring `__construct(ContainerInterface $c)` gets the scope,
 *    not the container.
 *
 *  - The RESERVED PATHS, at every tier this proxy covers
 *    ({@see self::RESERVED_PATHS}). The prefix already kept Community and
 *    Untrusted away from `/login`; Verified holds `RouteRegisterGlobal` and was
 *    kept away from it by nothing at all, while ADR-0023 said otherwise.
 *
 *  - A NAME the host's table already holds, at every tier this proxy covers
 *    ({@see self::assertNameIsAvailable()}). `Router` resolves a path collision
 *    first-registered-wins and a NAME collision last-wins, so taking the name
 *    `login` re-points every `route('login')` the host generates without
 *    touching the path table at all. Reserving `/login` closed the front door
 *    and left that one open one indirection further along.
 *
 * Read-only operations (match, routes, count) delegate; see ADR-0023 under
 * "What this does not stop" for what that discloses.
 *
 * @internal Not part of the public API
 */
final readonly class ScopedRouterProxy implements RouterInterface
{
    /**
     * Paths no extension may register at, whatever its tier.
     *
     * ADR-0023 has claimed since it was written that "reserved paths (/login,
     * /admin, /_studio, /api) cannot be shadowed by non-Core extensions", and
     * there was no reserved-path list anywhere in the tree. Community and
     * Untrusted were confined by the prefix, so the sentence was accidentally
     * true for them and plainly false for Verified, which holds
     * RouteRegisterGlobal and registered wherever it liked. A bundled product
     * extension at Verified could serve `/login`.
     *
     * The list is EXACT paths, not prefixes, and that is the whole design.
     * `/admin/tickets` is what `pulsar/tickets` is for and `/api/cms/...` is
     * what `pulsar/cms` is for; a prefix rule would refuse the extensions this
     * framework ships in order to protect a page they were never going to
     * shadow. What it protects is the reserved page ITSELF — the one a visitor
     * types, the one a redirect targets, the one a phishing route would want.
     *
     * Compared after prefixing, so a Community extension's own `/login`
     * (`/ext/acme-shop/login`) is its own business, and case-insensitively on a
     * slash-trimmed path so `/Login/` is the same claim as `/login`.
     *
     * @var list<string>
     */
    private const array RESERVED_PATHS = [
        'login',
        'logout',
        'admin',
        '_studio',
        'api',
    ];

    private string $pathPrefix;
    private string $namePrefix;

    /**
     * @param bool $confines False only for the collector proxy
     *                       {@see self::group()} hands to its callback, whose
     *                       routes come back through the confining `add()` of
     *                       the proxy that created it. Confining twice would
     *                       nest the prefix inside itself.
     */
    public function __construct(
        private RouterInterface $inner,
        private TrustTier $tier,
        private string $extensionName,
        private CapabilityPolicy $policy,
        private ?ScopedContainerProxy $scope = null,
        private bool $confines = true,
    ) {
        $this->pathPrefix = '/ext/' . $extensionName;
        $this->namePrefix = 'ext.' . str_replace('/', '.', $extensionName) . '.';
    }

    /**
     * The single registration door: confine the route, then hand it over.
     */
    #[Override]
    public function add(Route $route): self
    {
        $this->assertCanRegisterRoute($route->path);

        $confined = $this->confine($route);

        $this->assertNameIsAvailable($confined);
        $this->bindConstructionScope($confined);
        $this->inner->add($confined);

        return $this;
    }

    #[Override]
    public function get(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::get($path, $handler, $name));
    }

    #[Override]
    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::post($path, $handler, $name));
    }

    #[Override]
    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::put($path, $handler, $name));
    }

    #[Override]
    public function patch(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::patch($path, $handler, $name));
    }

    #[Override]
    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::delete($path, $handler, $name));
    }

    #[Override]
    public function any(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::any($path, $handler, $name));
    }

    /**
     * Register a group, without handing the callback a real router.
     *
     * {@see \Pulsar\Routing\Router::group()} builds a sub-router with `new
     * self()` and passes THAT to the callback. The callback is extension code,
     * so it was extension code holding an unproxied `Router` — every method on
     * it, including the ones this class exists to constrain.
     *
     * The callback now gets a proxy over a throwaway collector, and everything
     * it registers comes back through this proxy's own {@see self::add()} with
     * the group prefix in front. One confinement, applied at the one door.
     */
    #[Override]
    public function group(string $prefix, callable $callback): self
    {
        // The prefix is not itself a path anything is served at, so the
        // reserved-path check does not apply to it — `group('/admin', ...)`
        // holding `/admin/tickets` is what `pulsar/tickets` does, and refusing
        // it would refuse the extension in order to protect a page it never
        // registered. Each composed route reaches the reserved check in `add()`
        // below, so `group('/admin', fn($r) => $r->get('/', ...))` still is.
        $this->assertPathIsRegisterable($prefix);

        $collector = new Router();
        $callback(new self(
            $collector,
            $this->tier,
            $this->extensionName,
            $this->policy,
            $this->scope,
            confines: false,
        ));

        $groupPrefix = '/' . trim($prefix, '/');

        foreach ($collector->routes() as $route) {
            $this->add(self::withPath($route, $groupPrefix . '/' . ltrim($route->path, '/')));
        }

        return $this;
    }

    #[Override]
    public function match(Method $method, string $path, ?string $host = null): MatchedRoute
    {
        return $this->inner->match($method, $path, $host);
    }

    #[Override]
    #[NoDiscard]
    public function routes(): array
    {
        return $this->inner->routes();
    }

    /**
     * Register a resource route set — through `add()`, like everything else.
     *
     * This method and {@see self::apiResource()} were ADDED by the round of
     * fixes that reported route takeover closed, and both forwarded `$name`
     * straight to the inner router: `resource('admin', AdminController::class)`
     * from a Community extension registered seven routes at `/admin/...` named
     * `admin.index` and the rest, with no prefix anywhere. Building the set on a
     * throwaway router and re-adding each route here is what makes them
     * indistinguishable from seven `get()`/`post()` calls, which is what they
     * are.
     */
    #[Override]
    public function resource(string $name, string $controller, array $middleware = []): self
    {
        foreach (new Router()->resource($name, $controller, $middleware)->routes() as $route) {
            $this->add($route);
        }

        return $this;
    }

    #[Override]
    public function apiResource(string $name, string $controller, array $middleware = []): self
    {
        foreach (new Router()->apiResource($name, $controller, $middleware)->routes() as $route) {
            $this->add($route);
        }

        return $this;
    }

    /**
     * Declare a parameter-to-model binding — and require the GLOBAL route
     * capability to do it.
     *
     * This was the one mutating method on the proxy that asserted nothing, and
     * the prefixing that contains every other method does not reach it.
     * {@see \Pulsar\Routing\Router::model()} registers by parameter NAME, not by
     * path, so a binding declared here applies to every route in the
     * application that has a `{post}` in it — the host's routes included. There
     * is no `/ext/acme-analytics/` version of a parameter name to confine it to.
     *
     * What it can declare makes that reach a security decision rather than a
     * convenience: {@see BindingScope::Root} turns the containment check off, so
     * `model('post', Post::class, scope: BindingScope::Root)` from a community
     * extension unscopes `/users/{user}/posts/{post}` across the whole
     * application, and `modelClass` decides which class the middleware resolves
     * and the authorization hook is asked about.
     *
     * {@see ExtensionCapability::RouteRegisterGlobal} is therefore the right
     * gate, and it is the same one {@see assertCanRegisterRoute()} applies to a
     * wildcard path for the same reason: both are registrations a prefix cannot
     * contain. Core and Verified hold it; Community and Untrusted do not, so a
     * community extension keeps its prefixed routes and loses the ability to
     * rewrite the host's binding rules.
     */
    #[Override]
    public function model(
        string $parameter,
        string $modelClass,
        ?string $resolverClass = null,
        BindingScope $scope = BindingScope::Path,
        ?string $parentRelation = null,
    ): self {
        $this->assertRouteRegistration();

        if (!$this->policy->allows($this->tier, ExtensionCapability::RouteRegisterGlobal)) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::RouteRegisterGlobal);
        }

        // The resolver is a class the extension names and the framework builds,
        // so it is built through the scope like every other such class.
        if ($resolverClass !== null) {
            $this->scope?->provideThroughScope($resolverClass);
        }

        // Forwarded whole. A scope declaration dropped in transit would leave
        // an extension's route bound under a rule it did not ask for.
        $this->inner->model($parameter, $modelClass, $resolverClass, $scope, $parentRelation);

        return $this;
    }

    #[Override]
    public function count(): int
    {
        return $this->inner->count();
    }

    /**
     * Everything `add()` does to a route before the host's table sees it.
     */
    private function confine(Route $route): Route
    {
        if (!$this->confines) {
            return $route;
        }

        $path = $this->prefixPath($route->path);
        $name = $this->prefixName($route->name);
        $constraints = $this->reserveAgainstCatchAll($route);

        if ($path === $route->path && $name === $route->name && $constraints === $route->constraints) {
            return $route;
        }

        return new Route(
            methods: $route->methods,
            path: $path,
            handler: $route->handler,
            name: $name,
            attributes: $route->attributes,
            middleware: $route->middleware,
            constraints: $constraints,
            host: $route->host,
        );
    }

    /**
     * Keep a catch-all off the reserved paths, at match time.
     *
     * {@see self::assertCanRegisterRoute()} compares the path an extension
     * NAMES against {@see self::RESERVED_PATHS}, which settles a literal claim
     * on `/login` and settles nothing about `/{path}` — a route that names no
     * reserved path and answers all five.
     *
     * Two things already limit the damage and neither closes it.
     * `Router::match()` looks up static routes in an O(1) table BEFORE it scans
     * any dynamic bucket, so a host route at `/login` always wins; and a host
     * route registered earlier wins a key collision outright. Both are about a
     * host route that EXISTS. When the host has no `/login` — the auth
     * extension is off, the page has not been built yet — a catch-all is what
     * answers, and ADR-0023's promise that an extension cannot serve a reserved
     * path would hold only for the paths that did not need protecting.
     *
     * So the promise is compiled into the route instead of asserted about it: a
     * negative lookahead is prepended to the catch-all parameter's constraint,
     * and {@see Route::pathToPattern()} inlines constraints verbatim into the
     * compiled pattern, so the reserved segments cannot match no matter what
     * the router's precedence does with the route. Matched case-insensitively,
     * because {@see self::assertCanRegisterRoute()} lowercases before comparing
     * and `/Login` is the same claim.
     *
     * Only EXACT reserved paths, like the literal check: `/admin/cms/pages` is
     * what `pulsar/cms` is for and stays reachable through the catch-all.
     *
     * Core never reaches this class, and Community and Untrusted never reach
     * this method — a catch-all costs `RouteRegisterGlobal`, which neither
     * holds, and their paths are prefixed in any case.
     *
     * @return array<string, string>
     */
    private function reserveAgainstCatchAll(Route $route): array
    {
        if (!$this->isWildcardRoute($route->path)) {
            return $route->constraints;
        }

        if (preg_match('#^/?\{([a-zA-Z_][a-zA-Z0-9_]*)}$#', $route->path, $matches) !== 1) {
            return $route->constraints;
        }

        $parameter = $matches[1];
        $constraints = $route->constraints;
        $declared = $constraints[$parameter] ?? '[^/]+';

        $constraints[$parameter] = sprintf(
            '(?!(?i:%s)$)(?:%s)',
            implode('|', self::RESERVED_PATHS),
            $declared,
        );

        return $constraints;
    }

    /**
     * Bind the handler and middleware classes this route names to this
     * extension's scope.
     *
     * A handler is `Controller::class`, `[Controller::class, 'method']`, or a
     * closure; a middleware entry is a class name, a registry alias, or an
     * instance. The class-name forms are the ones the framework will construct
     * later, from a container that has no idea an extension named them — see
     * {@see ScopedContainerProxy::provideThroughScope()}. The rest need nothing:
     * a closure and an instance were built by the extension itself, out of
     * whatever it already legitimately held.
     */
    private function bindConstructionScope(Route $route): void
    {
        $scope = $this->scope;

        if ($scope === null) {
            return;
        }

        /** @var mixed $handler */
        $handler = $route->handler;

        if (is_string($handler)) {
            $scope->provideThroughScope($handler);
        } elseif (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            $scope->provideThroughScope($handler[0]);
        }

        foreach ($route->middleware as $middleware) {
            if (is_string($middleware)) {
                $scope->provideThroughScope($middleware);
            }
        }
    }

    /**
     * Refuse a route NAME the host's table already holds.
     *
     * A route name is a global key with different collision rules from a route
     * path, and the difference is the whole of this method.
     * {@see \Pulsar\Routing\Router::add()} resolves a PATH collision
     * first-registered-wins: the earlier route stays in the match tables and the
     * later one is recorded as a {@see \Pulsar\Routing\RouteCollision} for the
     * boot reporter. It resolves a NAME collision by writing
     * `$namedRoutes[$name] = $route` unconditionally — last-wins, silently.
     *
     * So `route('login')` in every one of the host's templates, redirects and
     * mail bodies points at whatever registered the name `login` LAST, and an
     * extension registering after the host is exactly that. ADR-0023 said "an
     * extension cannot displace a host route that was registered before it",
     * which was true of the path table and false of the one that generates URLs.
     *
     * Community and Untrusted were incidentally covered, because their names
     * carry the `ext.{name}.` prefix. Verified was not, and the exemption was
     * justified in the ADR by "it holds RouteRegisterGlobal and may register
     * `/login` outright" — a premise {@see self::RESERVED_PATHS} removed. A
     * Verified extension can no longer serve `/login` and could still take the
     * NAME of it, which is the same takeover one indirection further along:
     * the visitor clicks the host's own sign-in link and arrives at the
     * extension.
     *
     * The escape is the one `Router` uses for the path table, for the same
     * reason: a route cache replays the whole table before extensions boot
     * ({@see \Pulsar\Core\Kernel}), and every extension then re-registers what
     * it registered when the cache was written. A registration whose path and
     * handler match the route already holding the name is that replay and adds
     * nothing; anything else is a different route claiming a taken name.
     */
    private function assertNameIsAvailable(Route $route): void
    {
        $name = $route->name;

        if ($name === null) {
            return;
        }

        foreach ($this->inner->routes() as $existing) {
            if ($existing->name !== $name) {
                continue;
            }

            // Same route arriving twice — a cached-table replay, or the same
            // declaration registered for a second method.
            if ($existing->path === $route->path && $existing->handler == $route->handler) {
                return;
            }

            throw CapabilityDeniedException::forTakenRouteName($name, $existing->path, $this->tier);
        }
    }

    private function assertCanRegisterRoute(string $path): void
    {
        $this->assertPathIsRegisterable($path);

        // Judged on the path that will actually be registered: a Community
        // extension asking for `/login` is asking for `/ext/acme-shop/login`,
        // which shadows nothing.
        $claimed = strtolower(trim($this->prefixPath($path), '/'));

        if (in_array($claimed, self::RESERVED_PATHS, true)) {
            throw CapabilityDeniedException::forReservedPath($path, $this->tier);
        }
    }

    /**
     * What is asked of any path an extension names, registered or composed.
     *
     * A catch-all — a path that is one parameter segment and nothing else — is
     * the one route shape a prefix cannot contain, so it costs
     * `RouteRegisterGlobal`, the capability that exists for registrations a
     * prefix cannot contain and the same one {@see self::model()} charges.
     *
     * It used to be spelled `$this->tier === TrustTier::Verified`, a tier
     * comparison that contradicted the policy standing beside it:
     * {@see CapabilityPolicy::defaults()} grants Verified everything but
     * CryptoKeyAccess, ProcessExec and ContainerWrite, RouteRegisterGlobal
     * included, and this refused it anyway — while reporting the refusal as a
     * LACK of that capability, which the policy said the tier had. The same
     * shape as MiddlewareRegister, where the table claimed a control that
     * existed in neither direction, and with the same cost: `pulsar/cms`, a CMS,
     * could not register the catch-all that serves its content tree, so the
     * extension this framework ships at `verified` could not boot at `verified`
     * and stopped 166 routes short. Asking the policy makes the refusal mean
     * what it says: Community and Untrusted are not granted the capability and
     * are still refused.
     *
     * What a catch-all can and cannot do to the host is decided in
     * {@see self::reserveAgainstCatchAll()} and by `Router`'s own precedence,
     * not here.
     */
    private function assertPathIsRegisterable(string $path): void
    {
        $this->assertRouteRegistration();

        if (
            $this->isWildcardRoute($path)
            && !$this->policy->allows($this->tier, ExtensionCapability::RouteRegisterGlobal)
        ) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::RouteRegisterGlobal);
        }
    }

    /**
     * The capability every mutating method on this proxy needs before it may
     * touch the route table at all.
     *
     * Split out of {@see assertCanRegisterRoute()} because {@see model()} has no
     * path to check — it registers by parameter name — and a guard that demanded
     * one is how that method came to have no guard at all.
     */
    private function assertRouteRegistration(): void
    {
        if (!$this->policy->allows($this->tier, ExtensionCapability::RouteRegister)) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::RouteRegister);
        }
    }

    private function prefixPath(string $path): string
    {
        if ($this->tier->atLeast(TrustTier::Verified)) {
            return $path;
        }

        // Community and Untrusted: prefix with /ext/{extension-name}
        $path = rtrim($path, '/');

        if ($path === '' || $path === '/') {
            return $this->pathPrefix;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $this->pathPrefix . $path;
    }

    /**
     * Confine the route NAME to the extension's namespace, for the tiers whose
     * paths are confined.
     *
     * Verified is exempt for the same reason it is exempt from the path prefix:
     * it holds `RouteRegisterGlobal` and may register `/login` outright, so
     * denying it the NAME `login` would confine nothing while breaking every
     * bundled product extension's `route()` calls.
     */
    private function prefixName(?string $name): ?string
    {
        if ($name === null || $this->tier->atLeast(TrustTier::Verified)) {
            return $name;
        }

        if (str_starts_with($name, $this->namePrefix)) {
            return $name;
        }

        return $this->namePrefix . $name;
    }

    private function isWildcardRoute(string $path): bool
    {
        // Catch-all wildcard: route that is just a single parameter segment
        // e.g., /{any}, /{path}, /{slug}
        return (bool) preg_match('#^/?\{[a-zA-Z_][a-zA-Z0-9_]*}$#', $path);
    }

    private static function withPath(Route $route, string $path): Route
    {
        return new Route(
            methods: $route->methods,
            path: $path,
            handler: $route->handler,
            name: $route->name,
            attributes: $route->attributes,
            middleware: $route->middleware,
            constraints: $route->constraints,
            host: $route->host,
        );
    }
}
