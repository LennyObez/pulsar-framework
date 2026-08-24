<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\RouteAccess;
use Pulsar\Routing\RouteAccessRegistrar;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;
use Stringable;

use function in_array;

/**
 * The registrar is the one line a wiring writes instead of the router's verb
 * sugar, so what it puts on the route is the whole point.
 */
#[CoversClass(RouteAccessRegistrar::class)]
#[CoversClass(RouteAccess::class)]
final class RouteAccessRegistrarTest extends TestCase
{
    private Router $router;

    private MiddlewareRegistry $middleware;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->middleware = new MiddlewareRegistry();
    }

    #[Test]
    public function aPublicRouteCarriesTheDecisionAndTheReason(): void
    {
        $this->registrar()->publicRoute(
            [Method::GET],
            '/health',
            static fn(): string => 'ok',
            'pulsar.health',
            'load-balancer probe',
        );

        $route = $this->router->namedRoutes['pulsar.health'] ?? null;

        self::assertNotNull($route);
        self::assertSame(RouteAccess::Public, RouteAccess::of($route));
        self::assertSame('load-balancer probe', RouteAccess::reasonOf($route));
        self::assertSame([], $route->middleware, 'a public route must not be given an authorization guard');
    }

    #[Test]
    public function anOperatorRouteIsDeclaredWithoutBeingHandedToTheAuthAlias(): void
    {
        // The credential is a Bearer token checked in the handler, so attaching
        // AuthorizationMiddleware here would 401 the scraper it exists to serve.
        $this->registrar()->operatorRoute(
            [Method::GET],
            '/_pulsar/diagnostics',
            static fn(): string => 'ok',
            'pulsar.diagnostics',
            'guarded by DiagnosticsAuthGuard',
        );

        $route = $this->router->namedRoutes['pulsar.diagnostics'] ?? null;

        self::assertNotNull($route);
        self::assertSame(RouteAccess::Operator, RouteAccess::of($route));
        self::assertTrue(RouteAccess::Operator->requiresCredential());
        self::assertNotContains(RouteAccessRegistrar::AUTH_ALIAS, $route->middleware);
    }

    #[Test]
    public function aSignedRouteRecordsThatTheSignatureIsTheControl(): void
    {
        $this->registrar()->signedRoute(
            [Method::POST],
            '/webhooks/mail',
            static fn(): string => 'ok',
            'pulsar.mail.webhook',
            'provider HMAC over the body',
        );

        $route = $this->router->namedRoutes['pulsar.mail.webhook'] ?? null;

        self::assertNotNull($route);
        self::assertSame(RouteAccess::Signed, RouteAccess::of($route));
        self::assertArrayNotHasKey('permissions', $route->attributes, 'there is no identity to authorize');
    }

    /**
     * An authenticated route must carry BOTH halves: the `auth` alias, or
     * nothing resolves an identity, and a permission, or
     * {@see AuthorizationMiddleware} default-denies it for everyone.
     */
    #[Test]
    public function anAuthenticatedRouteCarriesBothTheAliasAndThePermission(): void
    {
        $this->middleware->alias(RouteAccessRegistrar::AUTH_ALIAS, AuthorizationMiddleware::class);

        $this->registrar()->authenticated(
            [Method::GET],
            '/api/docs',
            static fn(): string => 'ok',
            'api.docs.ui',
            ['api.docs.read'],
            'publishes the API contract',
        );

        $route = $this->router->namedRoutes['api.docs.ui'] ?? null;

        self::assertNotNull($route);
        self::assertSame(RouteAccess::Authenticated, RouteAccess::of($route));
        self::assertContains(RouteAccessRegistrar::AUTH_ALIAS, $route->middleware);
        self::assertSame(['api.docs.read'], $route->attributes['permissions'] ?? null);
    }

    /**
     * The fail-closed case, and the reason the registrar takes the middleware
     * registry at all.
     *
     * With `security.auth` unset, AuthWiring returns before publishing the
     * `auth` alias. A route registered anyway would dispatch straight to its
     * handler with the permission list sitting inertly in its attributes — a
     * guarded route on paper, an open one in the process. Not registering it is
     * the honest answer: 404 for a feature whose authorization is absent.
     */
    #[Test]
    public function anAuthenticatedRouteIsNotRegisteredWhenNothingCanEnforceIt(): void
    {
        $logger = self::recordingLogger();

        new RouteAccessRegistrar($this->router, $this->middleware, $logger)->authenticated(
            [Method::POST],
            '/broadcasting/auth',
            static fn(): string => 'ok',
            'pulsar.broadcasting.auth',
            [RouteAccessRegistrar::ANY_AUTHENTICATED],
            'identity-bound channel grant',
        );

        self::assertSame([], $this->router->routes, 'the route must not exist without its guard');

        /** @var list<string> $lines */
        $lines = $logger->lines;
        self::assertNotSame([], $lines, 'the omission must be logged, not silent');
        self::assertStringContainsString('/broadcasting/auth', $lines[0]);
    }

    /**
     * An empty permission list is not a grant; AuthorizationMiddleware reads it
     * as deny-everyone. Registering it would produce a route that looks guarded
     * and is simply broken, which is the failure mode the whole exercise is
     * about.
     */
    #[Test]
    public function anEmptyPermissionListIsRejectedRatherThanRegistered(): void
    {
        $this->middleware->alias(RouteAccessRegistrar::AUTH_ALIAS, AuthorizationMiddleware::class);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessageMatches('/names no permission/');

        $this->registrar()->authenticated(
            [Method::GET],
            '/admin',
            static fn(): string => 'ok',
            'admin',
            [],
            'should never register',
        );
    }

    /** A GET registration still serves HEAD, as every other registration path does. */
    #[Test]
    public function aGetRegistrationStillServesHead(): void
    {
        $this->registrar()->publicRoute([Method::GET], '/ui/{path}', static fn(): string => 'ok', 'ui', 'assets');

        $route = $this->router->namedRoutes['ui'] ?? null;

        self::assertNotNull($route);
        self::assertTrue(in_array(Method::HEAD, $route->methods, true));
    }

    #[Test]
    public function constraintsReachTheRoute(): void
    {
        $this->registrar()->publicRoute(
            [Method::GET],
            '/ui/{path}',
            static fn(): string => 'ok',
            'ui.constrained',
            'assets',
            constraints: ['path' => '.+'],
        );

        $route = $this->router->namedRoutes['ui.constrained'] ?? null;

        self::assertNotNull($route);
        self::assertSame(['path' => '.+'], $route->constraints);
    }

    private function registrar(): RouteAccessRegistrar
    {
        return new RouteAccessRegistrar($this->router, $this->middleware);
    }

    /**
     * @return LoggerInterface&object{lines: list<string>}
     */
    private static function recordingLogger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            /**
             * @param mixed             $level
             * @param string|Stringable $message
             * @param array<mixed>      $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
    }
}
