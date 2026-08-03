<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Routing\Router;

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

    #[Test]
    public function verifiedCannotRegisterWildcardRoutes(): void
    {
        $proxy = $this->proxy(TrustTier::Verified, 'acme/verified');

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

    // --- Route name preservation ---

    #[Test]
    public function communityRouteNamePreserved(): void
    {
        $proxy = $this->proxy(TrustTier::Community, 'acme/test');
        $proxy->get('/page', fn() => 'ok', 'acme.page');

        $routes = $this->router->routes();
        self::assertSame('acme.page', $routes[0]->name);
    }
}
