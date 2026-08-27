<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

use function count;
use function in_array;
use function sort;
use function sprintf;
use function str_starts_with;

#[CoversClass(ScopedRouterProxy::class)]
final class ScopedRouterProxyTest extends TestCase
{
    private Router $router;
    private CapabilityPolicy $policy;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->policy = CapabilityPolicy::defaults();
    }

    private function proxy(TrustTier $tier, string $extensionName = 'acme/test'): ScopedRouterProxy
    {
        return new ScopedRouterProxy(
            $this->router,
            $tier,
            $extensionName,
            $this->policy,
        );
    }

    // --- Community tier: prefix enforcement ---

    #[Test]
    public function communityRoutesArePrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/analytics');
        $proxy->get('/dashboard', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertCount(1, $routes);
        self::assertSame('/ext/acme/analytics/dashboard', $routes[0]->path);
    }

    #[Test]
    public function communityRootRouteGetsPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->get('/', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/ext/acme/test', $routes[0]->path);
    }

    #[Test]
    public function communityPostRoutesPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->post('/submit', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/ext/acme/test/submit', $routes[0]->path);
    }

    #[Test]
    public function communityPutRoutesPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->put('/update', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/ext/acme/test/update', $routes[0]->path);
    }

    #[Test]
    public function communityPatchRoutesPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->patch('/fix', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/ext/acme/test/fix', $routes[0]->path);
    }

    #[Test]
    public function communityDeleteRoutesPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->delete('/remove', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/ext/acme/test/remove', $routes[0]->path);
    }

    #[Test]
    public function communityAnyRoutesPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->any('/handler', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/ext/acme/test/handler', $routes[0]->path);
    }

    // --- Untrusted tier: denied ---

    #[Test]
    public function untrustedCannotRegisterRoutes(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('RouteRegister');
        $proxy->get('/anything', fn() => 'ok');
    }

    // --- Verified tier: no prefix, but no wildcards ---

    #[Test]
    public function verifiedRoutesNotPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Verified, 'acme/verified');
        $proxy->get('/dashboard', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/dashboard', $routes[0]->path);
    }

    /**
     * A catch-all costs `RouteRegisterGlobal`, and Verified holds it.
     *
     * This assertion used to be the opposite, because the check was spelled
     * `$this->tier === TrustTier::Verified` while
     * {@see \Pulsar\Extensibility\CapabilityPolicy::defaults()} granted Verified
     * every capability but CryptoKeyAccess, ProcessExec and ContainerWrite —
     * RouteRegisterGlobal among them. The proxy refused the tier the thing it
     * reported the tier as lacking, and it cost `pulsar/cms` the catch-all a CMS
     * is built around: it could not boot at the tier this framework ships it at.
     */
    #[Test]
    public function verifiedRegistersACatchAllBecauseItHoldsTheGlobalCapability(): void
    {
        $proxy = $this->proxy(TrustTier::Verified, 'acme/verified');
        $proxy->get('/{any}', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/{any}', $routes[0]->path);
    }

    /**
     * And is still not allowed to answer a reserved path with it. The literal
     * reserved-path check cannot see this case — the route names no reserved
     * path — so the refusal is compiled into the parameter's constraint.
     */
    #[Test]
    public function averifiedCatchAllCannotMatchAReservedPath(): void
    {
        $proxy = $this->proxy(TrustTier::Verified, 'acme/verified');
        $proxy->get('/{any}', fn() => 'ok');

        $route = $this->router->routes()[0];

        foreach (['/login', '/logout', '/admin', '/_studio', '/api', '/Admin'] as $reserved) {
            self::assertNull($route->matchesPath($reserved), $reserved . ' was answered by the catch-all');
        }

        self::assertNotNull($route->matchesPath('/anything-else'));
    }

    #[Test]
    public function communityCannotRegisterWildcardRoutes(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/community');

        $this->expectException(CapabilityDeniedException::class);
        $proxy->get('/{any}', fn() => 'ok');
    }

    // --- Core tier: full access ---

    #[Test]
    public function coreRoutesNotPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Core, 'pulsar/admin');
        $proxy->get('/admin/dashboard', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/admin/dashboard', $routes[0]->path);
    }

    #[Test]
    public function coreCanRegisterWildcardRoutes(): void
    {
        $proxy = $this->proxy(TrustTier::Core, 'pulsar/core');
        $proxy->get('/{any}', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/{any}', $routes[0]->path);
    }

    // --- Reserved path protection ---

    #[Test]
    public function communityCannotShadowLoginRoute(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/evil');

        // Community routes are prefixed, so they can never shadow /login
        // The prefix enforcement handles this automatically
        $proxy->get('/login', fn() => 'ok');

        $routes = $this->router->routes();
        self::assertSame('/ext/acme/evil/login', $routes[0]->path);
    }

    // --- Read-only operations always allowed ---

    #[Test]
    public function untrustedCanReadRoutes(): void
    {
        $this->router->get('/existing', fn() => 'ok');
        $proxy = $this->proxy(TrustTier::Untrusted);

        self::assertCount(1, $proxy->routes());
        self::assertSame(1, $proxy->count());
    }

    // --- Reserved paths: the guarantee ADR-0023 made and nothing implemented ---

    /**
     * ADR-0023 has said since it was written that reserved paths "cannot be
     * shadowed by non-Core extensions", and there was no reserved-path list
     * anywhere in the tree. Community and Untrusted were kept off `/login` by
     * the `/ext/` prefix, which made the claim accidentally true for them.
     * Verified holds RouteRegisterGlobal and was kept off it by nothing.
     */
    #[Test]
    public function verifiedCannotShadowAReservedPath(): void
    {
        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('reserves');
        $this->proxy(TrustTier::Verified)->get('/login', fn() => 'phish');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedPaths(): iterable
    {
        yield 'login' => ['/login'];
        yield 'logout' => ['/logout'];
        yield 'admin' => ['/admin'];
        yield 'studio' => ['/_studio'];
        yield 'api' => ['/api'];
        yield 'trailing slash' => ['/admin/'];
        yield 'different case' => ['/Admin'];
    }

    #[Test]
    #[DataProvider('reservedPaths')]
    public function everyReservedPathIsRefused(string $path): void
    {
        $this->expectException(CapabilityDeniedException::class);
        $this->proxy(TrustTier::Verified)->get($path, fn() => 'nope');
    }

    /**
     * EXACT paths, not prefixes, and the difference is the whole design.
     *
     * `/admin/tickets` is what `pulsar/tickets` exists to serve and
     * `/api/cms/...` is what `pulsar/cms` exists to serve; a prefix rule would
     * refuse the extensions this framework ships in order to protect a page
     * they were never going to shadow.
     */
    #[Test]
    public function aPathBeneathAReservedOneIsNotReserved(): void
    {
        $this->proxy(TrustTier::Verified)->get('/admin/tickets', fn() => 'ok', 'tickets.admin');

        self::assertSame('/admin/tickets', $this->router->routes()[0]->path);
    }

    /**
     * The reserved check runs on the path that will actually be registered, so
     * a Community extension's own login page is its own business.
     */
    #[Test]
    public function communityMayRegisterItsOwnLoginUnderItsPrefix(): void
    {
        $this->proxy(TrustTier::Community, 'acme/shop')->get('/login', fn() => 'ok');

        self::assertSame('/ext/acme/shop/login', $this->router->routes()[0]->path);
    }

    /**
     * A reserved path cannot be reached through a group either, because a
     * grouped route re-enters the same door.
     */
    #[Test]
    public function aGroupCannotComposeItsWayOntoAReservedPath(): void
    {
        $this->expectException(CapabilityDeniedException::class);
        $this->proxy(TrustTier::Verified)->group('/admin', static function (ScopedRouterProxy $sub): void {
            $sub->get('/', static fn(): string => 'phish');
        });
    }

    /**
     * The group PREFIX is not a path anything is served at, so it is not
     * judged as one.
     *
     * `group('/admin', ...)` holding `/admin/tickets` is what `pulsar/tickets`
     * does; refusing the prefix would refuse the extension in order to protect
     * a page it never registered.
     */
    #[Test]
    public function aGroupMayBePrefixedWithAReservedSegment(): void
    {
        $this->proxy(TrustTier::Verified)->group('/admin', static function (ScopedRouterProxy $sub): void {
            $sub->get('/tickets', static fn(): string => 'ok', 'tickets.admin');
        });

        self::assertSame('/admin/tickets', $this->router->routes()[0]->path);
    }

    // --- Reserved paths close the front door; the NAME table is the side one ---

    /**
     * Reserving `/login` and leaving the NAME `login` available closes nothing.
     *
     * `Router::add()` resolves a PATH collision first-registered-wins and a NAME
     * collision by overwriting `$namedRoutes[$name]`. ADR-0023 claimed that
     * "registration is first-registered-wins in Router, so an extension cannot
     * displace a host route that was registered before it" — true of the table
     * that matches requests, false of the table that generates URLs. A Verified
     * extension that can no longer serve `/login` could still become the
     * destination of every `route('login')` in the host's templates, redirects
     * and mail bodies, which is the same takeover one indirection along.
     */
    #[Test]
    public function verifiedCannotTakeTheNameOfAHostRoute(): void
    {
        $this->router->get('/login', static fn(): string => 'the real one', 'login');

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('already');
        $this->proxy(TrustTier::Verified)->get('/promo', static fn(): string => 'phish', 'login');
    }

    /**
     * The host's route keeps its name, and keeps generating its own URL.
     */
    #[Test]
    public function aRefusedNameLeavesTheHostRouteInPlace(): void
    {
        $this->router->get('/login', static fn(): string => 'the real one', 'login');

        try {
            $this->proxy(TrustTier::Verified)->get('/promo', static fn(): string => 'phish', 'login');
        } catch (CapabilityDeniedException) {
            // asserted above; here the table is what matters
        }

        self::assertSame('/login', $this->router->namedRoutes['login']->path);
        self::assertCount(1, $this->router->routes());
    }

    /**
     * One extension cannot take another's name either. The host table is one
     * namespace and the rule is about the table, not about who owns the entry.
     */
    #[Test]
    public function oneExtensionCannotTakeAnothersName(): void
    {
        $this->proxy(TrustTier::Verified, 'acme/first')->get('/first', static fn(): string => 'ok', 'reports');

        $this->expectException(CapabilityDeniedException::class);
        $this->proxy(TrustTier::Verified, 'acme/second')->get('/second', static fn(): string => 'nope', 'reports');
    }

    /**
     * Community and Untrusted were already covered, by the `ext.{name}.` prefix
     * rather than by this rule: their `login` is `ext.acme.shop.login`, which
     * collides with nothing.
     */
    #[Test]
    public function communityKeepsItsOwnLoginNameBecauseItIsPrefixed(): void
    {
        $this->router->get('/login', static fn(): string => 'the real one', 'login');
        $this->proxy(TrustTier::Community, 'acme/shop')->get('/signin', static fn(): string => 'ok', 'login');

        self::assertSame('/login', $this->router->namedRoutes['login']->path);
        self::assertSame('/ext/acme/shop/signin', $this->router->namedRoutes['ext.acme.shop.login']->path);
    }

    /**
     * A route cache replays the whole table before extensions boot, and every
     * extension then re-registers what it registered when the cache was
     * written. That arrival is the same route, not a claim on a taken name —
     * the same escape `Router::add()` applies to the path table, for the same
     * reason.
     */
    #[Test]
    public function reRegisteringTheSameRouteIsNotATakeover(): void
    {
        $handler = static fn(): string => 'ok';

        $this->router->add(Route::get('/reports', $handler, 'reports'));

        $this->proxy(TrustTier::Verified)->get('/reports', $handler, 'reports');

        self::assertSame('/reports', $this->router->namedRoutes['reports']->path);
    }

    /**
     * A name nothing holds is free, whatever the tier.
     */
    #[Test]
    public function anUnusedNameIsRegisteredNormally(): void
    {
        $this->proxy(TrustTier::Verified)->get('/reports', static fn(): string => 'ok', 'reports');

        self::assertSame('/reports', $this->router->namedRoutes['reports']->path);
    }

    /**
     * A group composes its routes and re-enters `add()`, so the name is judged
     * there like every other confinement.
     */
    #[Test]
    public function aGroupCannotComposeItsWayOntoATakenName(): void
    {
        $this->router->get('/login', static fn(): string => 'the real one', 'login');

        $this->expectException(CapabilityDeniedException::class);
        $this->proxy(TrustTier::Verified)->group('/promo', static function (ScopedRouterProxy $sub): void {
            $sub->get('/signin', static fn(): string => 'phish', 'login');
        });
    }

    /**
     * `resource()` names seven routes at once, so it is seven chances to take
     * one. It goes through the same door.
     */
    #[Test]
    public function aResourceSetCannotTakeATakenName(): void
    {
        $this->router->get('/anything', static fn(): string => 'the real one', 'reports.index');

        $this->expectException(CapabilityDeniedException::class);
        $this->proxy(TrustTier::Verified)->resource('reports', ProxyBoundController::class);
    }

    // --- Route names are confined exactly as paths are ---

    /**
     * A route NAME is a global key, and the prefix used to confine only the
     * path.
     *
     * `Router::add()` writes `$namedRoutes[$name]` last-wins, so a Community
     * extension registering a route called `login` re-pointed every
     * `route('login')` in the host's templates and redirects at its own handler
     * — while its path sat dutifully under `/ext/`. Half a confinement.
     */
    #[Test]
    public function communityRouteNamesArePrefixedLikePaths(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->get('/page', fn() => 'ok', 'acme.page');

        $routes = $this->router->routes();
        self::assertSame('/ext/acme/test/page', $routes[0]->path);
        self::assertSame('ext.acme.test.acme.page', $routes[0]->name);
    }

    #[Test]
    public function communityCannotTakeOverTheHostsNamedRoute(): void
    {
        $this->router->get('/login', fn() => 'host', 'login');
        $this->proxy(TrustTier::Community, 'acme/evil')->get('/login', fn() => 'evil', 'login');

        self::assertSame(
            '/login',
            $this->router->getByName('login')?->path,
            'URL generation for the name "login" must still reach the host route',
        );
    }

    /**
     * Verified is exempt from the name prefix for the same reason it is exempt
     * from the path prefix: it holds RouteRegisterGlobal and may register
     * `/login` outright, so confining the NAME would break every bundled
     * product extension's own URL generation while confining nothing.
     */
    #[Test]
    public function verifiedRouteNamesAreNotPrefixed(): void
    {
        $proxy = $this->proxy(TrustTier::Verified, 'acme/test');
        $proxy->get('/page', fn() => 'ok', 'acme.page');

        self::assertSame('acme.page', $this->router->routes()[0]->name);
    }

    /**
     * Prefixing is not applied twice when a route re-enters `add()`, which is
     * exactly what `group()` makes happen.
     */
    #[Test]
    public function aNameAlreadyInsideThePrefixIsNotPrefixedAgain(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->get('/page', fn() => 'ok', 'ext.acme.test.acme.page');

        self::assertSame('ext.acme.test.acme.page', $this->router->routes()[0]->name);
    }

    // --- resource()/apiResource(): the two the last round added open ---

    /**
     * `resource()` and `apiResource()` were ADDED by the round of fixes that
     * reported route takeover closed, and both forwarded the resource name to
     * the inner router unprefixed — so seven routes landed at `/admin/...`,
     * named `admin.index` and the rest, from a Community extension.
     */
    #[Test]
    public function communityResourceRoutesArePrefixedLikeEveryOtherRegistration(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/evil');
        $proxy->resource('admin', ProxyBoundController::class);

        $routes = $this->router->routes();
        self::assertCount(7, $routes);

        foreach ($routes as $route) {
            self::assertStringStartsWith('/ext/acme/evil/admin', $route->path);
            self::assertNotNull($route->name);
            self::assertStringStartsWith('ext.acme.evil.', $route->name);
        }
    }

    #[Test]
    public function communityApiResourceRoutesArePrefixedLikeEveryOtherRegistration(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/evil');
        $proxy->apiResource('admin', ProxyBoundController::class);

        $routes = $this->router->routes();
        self::assertCount(5, $routes);

        foreach ($routes as $route) {
            self::assertStringStartsWith('/ext/acme/evil/admin', $route->path);
            self::assertNotNull($route->name);
            self::assertStringStartsWith('ext.acme.evil.', $route->name);
        }
    }

    #[Test]
    public function untrustedCannotRegisterAResourceSet(): void
    {
        $this->expectException(CapabilityDeniedException::class);
        $this->proxy(TrustTier::Untrusted)->resource('admin', ProxyBoundController::class);
    }

    // --- group(): the callback used to receive a real Router ---

    /**
     * `Router::group()` builds its sub-router with `new self()` and hands THAT
     * to the callback, so the callback — extension code — held an unproxied
     * router carrying every method this class exists to constrain.
     */
    #[Test]
    public function aGroupCallbackNeverReceivesAnUnscopedRouter(): void
    {
        $received = null;

        $this->proxy(TrustTier::Community, 'acme/test')->group(
            '/admin',
            static function (object $sub) use (&$received): void {
                $received = $sub;
            },
        );

        self::assertInstanceOf(ScopedRouterProxy::class, $received);
    }

    #[Test]
    public function groupedRoutesAreConfinedExactlyOnce(): void
    {
        $this->proxy(TrustTier::Community, 'acme/test')->group(
            '/admin',
            static function (ScopedRouterProxy $sub): void {
                $sub->get('/users', static fn(): string => 'ok', 'admin.users');
            },
        );

        $routes = $this->router->routes();
        self::assertCount(1, $routes);
        self::assertSame('/ext/acme/test/admin/users', $routes[0]->path);
        self::assertSame('ext.acme.test.admin.users', $routes[0]->name);
    }

    // --- Binding declarations are route registrations, and are gated as such ---

    #[Test]
    public function communityCannotDeclareABindingScope(): void
    {
        // model() was the one mutating method on this proxy that asserted
        // nothing. It registers by parameter NAME, so there is no prefix that
        // could confine it: `{post}` here is the host's `{post}`.
        $proxy = $this->proxy(TrustTier::Community, 'acme/evil');

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('RouteRegisterGlobal');

        $proxy->model('post', ProxyBoundModel::class, scope: BindingScope::Root);
    }

    #[Test]
    public function aDeniedBindingDeclarationNeverReachesTheRouter(): void
    {
        // The interesting half of the refusal. BindingScope::Root turns the
        // containment check off for every route in the application with a
        // {post} in it, so a declaration that leaked through the guard would
        // unscope the host's /users/{user}/posts/{post} from a community
        // extension.
        $proxy = $this->proxy(TrustTier::Community, 'acme/evil');

        try {
            $proxy->model('post', ProxyBoundModel::class, scope: BindingScope::Root);
        } catch (CapabilityDeniedException) {
            // Expected; the assertion below is what this test is about.
        }

        self::assertSame([], $this->router->explicitBindings);
    }

    #[Test]
    public function untrustedCannotDeclareABindingScope(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);

        $this->expectException(CapabilityDeniedException::class);
        $this->expectExceptionMessageIsOrContains('RouteRegister');

        $proxy->model('post', ProxyBoundModel::class);
    }

    #[Test]
    public function verifiedMayDeclareABindingScope(): void
    {
        // The control. Verified holds RouteRegisterGlobal — it registers routes
        // at any path — so a binding declaration with the same reach is within
        // what it is already trusted with, and must keep working.
        $proxy = $this->proxy(TrustTier::Verified, 'acme/verified');
        $proxy->model('post', ProxyBoundModel::class, scope: BindingScope::Root);

        $bindings = $this->router->explicitBindings;
        self::assertCount(1, $bindings);
        self::assertSame('post', $bindings[0]->parameter);
        self::assertSame(BindingScope::Root, $bindings[0]->scope);
    }

    #[Test]
    public function coreMayDeclareABindingScope(): void
    {
        $proxy = $this->proxy(TrustTier::Core, 'pulsar/orm');
        $proxy->model('post', ProxyBoundModel::class, resolverClass: null, parentRelation: null);

        self::assertCount(1, $this->router->explicitBindings);
    }

    // --- Every mutating method is gated, including the next one added ---

    /**
     * The read-only surface of the proxy, enumerated in exactly one place.
     *
     * {@see everyMutatingMethodIsGated()} treats every other public method as
     * mutating and requires it to refuse an ungranted tier. A method added to
     * this proxy without a guard therefore fails that test until someone either
     * guards it or writes its name here — and writing its name here is a claim
     * that it does not touch the route table, which
     * {@see readOnlyMethodsAreNotGatedAndChangeNothing()} then checks.
     */
    private const array READ_ONLY_METHODS = ['match', 'routes', 'count'];

    #[Test]
    public function everyMutatingMethodIsGated(): void
    {
        $proxy = $this->proxy(TrustTier::Untrusted);
        $mutating = self::mutatingMethods();

        self::assertContains('model', $mutating, 'model() mutates the route table and must be enumerated here');
        self::assertGreaterThan(count(self::READ_ONLY_METHODS), count($mutating));

        foreach ($mutating as $name) {
            $method = new ReflectionMethod(ScopedRouterProxy::class, $name);

            try {
                $method->invokeArgs($proxy, self::argumentsFor($method));
                self::fail(sprintf(
                    'ScopedRouterProxy::%s() mutates the router without asserting a capability first',
                    $name,
                ));
            } catch (CapabilityDeniedException) {
                // Gated, which is the property under test.
            }
        }

        self::assertSame([], $this->router->routes(), 'a denied call must not reach the inner router');
        self::assertSame([], $this->router->explicitBindings);
    }

    #[Test]
    public function readOnlyMethodsAreNotGatedAndChangeNothing(): void
    {
        // The other half: naming a method read-only in READ_ONLY_METHODS is a
        // claim, and this is where the claim is checked. An untrusted extension
        // may still read the route table, and reading it may not change it.
        $this->router->get('/existing', fn() => 'ok');
        $proxy = $this->proxy(TrustTier::Untrusted);

        $before = $this->router->routes();

        foreach (self::READ_ONLY_METHODS as $name) {
            $method = new ReflectionMethod(ScopedRouterProxy::class, $name);

            try {
                $method->invokeArgs($proxy, self::argumentsFor($method));
            } catch (CapabilityDeniedException $denied) {
                self::fail(sprintf('%s() is enumerated read-only but refused: %s', $name, $denied->getMessage()));
            } catch (Throwable) {
                // match() on a path this fixture does not serve raises a routing
                // exception. Anything other than a capability refusal is fine —
                // the point is the guard, not the lookup.
            }
        }

        self::assertEquals($before, $this->router->routes());
        self::assertSame([], $this->router->explicitBindings);
    }

    /**
     * Every public method of the proxy that is not enumerated read-only.
     *
     * Read from the class rather than from a list, so the enumeration cannot
     * fall behind the class it describes.
     *
     * @return list<string>
     */
    private static function mutatingMethods(): array
    {
        $methods = [];

        foreach (new ReflectionClass(ScopedRouterProxy::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic() || $method->isConstructor() || str_starts_with($name, '__')) {
                continue;
            }

            if (in_array($name, self::READ_ONLY_METHODS, true)) {
                continue;
            }

            $methods[] = $name;
        }

        sort($methods);

        return $methods;
    }

    /**
     * Build a call for a proxy method from its signature alone.
     *
     * Values are chosen to be accepted by the parameter types and by nothing
     * else — the call has to get far enough to be refused, so a guard that is
     * missing shows up as a successful mutation rather than as a TypeError.
     *
     * @return list<mixed>
     */
    private static function argumentsFor(ReflectionMethod $method): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $arguments[] = self::argumentFor($parameter);
        }

        return $arguments;
    }

    private static function argumentFor(ReflectionParameter $parameter): mixed
    {
        // Two parameters are declared `string` but are read as class names, so
        // a generic string would fail on its way past the guard rather than at
        // it.
        $byName = [
            'modelClass' => ProxyBoundModel::class,
            'controller' => ProxyBoundController::class,
        ];

        if (isset($byName[$parameter->getName()])) {
            return $byName[$parameter->getName()];
        }

        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $parameter->allowsNull() ? null : 'probe';
        }

        if ($type->allowsNull()) {
            return null;
        }

        return match ($type->getName()) {
            'string' => 'probe',
            'array' => [],
            'callable', 'mixed' => static fn(): string => 'ok',
            Route::class => new Route([Method::GET], '/probe', static fn(): string => 'ok'),
            Method::class => Method::GET,
            BindingScope::class => BindingScope::Path,
            default => null,
        };
    }

    /**
     * Route capabilities cannot be granted per-extension, only per-tier.
     *
     * `ScopedContainerProxy` takes an `additionalCapabilities` list and consults
     * it, so a host can hand one Community extension `DatabaseRaw` without
     * promoting it. The router proxy takes no such list — it answers from the
     * tier and the policy alone — so `additional_capabilities` naming
     * `RouteRegister` or `RouteRegisterGlobal` in `config/extensions.php` has no
     * effect and reports no error.
     *
     * This is pinned rather than fixed because the failure is a REFUSAL: the
     * host gets less than it asked for, never more, so the boundary holds and
     * changing it would mean widening privilege inside a change whose purpose
     * was to narrow it. It is recorded here and in ADR-0023 so the silence is at
     * least documented; if the grant is ever wired through, this test fails and
     * the documentation gets corrected with it.
     */
    #[Test]
    public function routeCapabilitiesCannotBeGrantedPerExtension(): void
    {
        $accepted = [];

        foreach (new ReflectionMethod(ScopedRouterProxy::class, '__construct')->getParameters() as $parameter) {
            $accepted[] = $parameter->getName();
        }

        self::assertSame(
            ['inner', 'tier', 'extensionName', 'policy', 'scope', 'confines'],
            $accepted,
            'ScopedRouterProxy gained or lost a constructor parameter — if it now accepts '
            . 'per-extension capabilities, wire them into the route assertions and correct '
            . 'ADR-0023, which states that route capabilities are tier-only. "scope" is the '
            . 'container scope a route handler is constructed through and "confines" is false '
            . 'only for the collector group() hands its callback; neither is a capability.',
        );

        // The consequence: no grant reaches this, so Community stays refused.
        $this->expectException(CapabilityDeniedException::class);
        $this->proxy(TrustTier::Community)->model('post', ProxyBoundController::class);
    }
}

/**
 * A class name for the `modelClass` parameter of model().
 *
 * @internal
 */
final class ProxyBoundModel {}

/**
 * A class name for the `controller` parameter of resource()/apiResource().
 *
 * @internal
 */
final class ProxyBoundController {}
