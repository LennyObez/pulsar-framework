<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function json_decode;

#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    private function createRequest(string $path = '/test'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
        );
    }

    private function createSecurityContextWithIdentity(
        IdentityInterface $identity,
        ServerRequestInterface $request,
    ): SecurityContext {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        return new SecurityContext($authManager, $request);
    }

    #[Test]
    public function returns401WhenNoSecurityContextOnRequest(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createRequest();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenIdentityIsNotAuthenticated(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createRequest();
        $securityContext = $this->createSecurityContextWithIdentity(
            new AnonymousIdentity(),
            $request,
        );
        $request = $request->withAttribute('_security_context', $securityContext);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function returns200WhenIdentityIsAuthenticatedAndHasPermission(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createRequest('/admin/users');
        $securityContext = $this->createSecurityContextWithIdentity($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $route = new Route(
            methods: [Method::GET],
            path: '/admin/users',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => ['users.view']],
        );
        // The route arrives as an argument, not as an attribute: the pipeline
        // binds it before the chain is built, which is the only channel no frame
        // between routing and here sits on. Writing `_route` instead would be
        // testing the substitutable channel this middleware stopped reading.
        $middleware = $middleware->forDispatchedRoute(new MatchedRoute(route: $route));

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function returns403WhenIdentityLacksRequiredPermission(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['viewer'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createRequest('/admin/users');
        $securityContext = $this->createSecurityContextWithIdentity($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $route = new Route(
            methods: [Method::GET],
            path: '/admin/users',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => ['users.view']],
        );
        // The route arrives as an argument, not as an attribute: the pipeline
        // binds it before the chain is built, which is the only channel no frame
        // between routing and here sits on. Writing `_route` instead would be
        // testing the substitutable channel this middleware stopped reading.
        $middleware = $middleware->forDispatchedRoute(new MatchedRoute(route: $route));

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function readsPermissionsFromRouteAttributes(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::once())
            ->method('denies')
            ->with(
                self::isInstanceOf(IdentityInterface::class),
                'users.view',
                self::isInstanceOf(PolicyContext::class),
            )
            ->willReturn(false);

        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createRequest('/admin/users');
        $securityContext = $this->createSecurityContextWithIdentity($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $route = new Route(
            methods: [Method::GET],
            path: '/admin/users',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => ['users.view']],
        );
        // The route arrives as an argument, not as an attribute: the pipeline
        // binds it before the chain is built, which is the only channel no frame
        // between routing and here sits on. Writing `_route` instead would be
        // testing the substitutable channel this middleware stopped reading.
        $middleware = $middleware->forDispatchedRoute(new MatchedRoute(route: $route));

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $middleware->process($request, $handler);
    }

    /**
     * A route middleware in front of `auth` cannot choose the permissions.
     *
     * This frame is registered as the `auth` ALIAS and therefore runs in the
     * route-level pipeline, where other route middleware runs before it. It used
     * to read the required permissions from the `_route` request attribute, so a
     * frame ahead of it could hand it a route declaring the documented
     * `_authenticated` sentinel and every RBAC check on the real route was
     * skipped: the gate was never called, and the handler ran.
     */
    #[Test]
    public function aRewrittenRouteAttributeCannotChangeThePermissionsChecked(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['viewer'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::once())
            ->method('denies')
            ->with(self::isInstanceOf(IdentityInterface::class), 'admin.super', self::isInstanceOf(PolicyContext::class))
            ->willReturn(true);

        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createRequest('/admin/super');
        $request = $request->withAttribute(
            '_security_context',
            $this->createSecurityContextWithIdentity($identity, $request),
        );

        $dispatched = new MatchedRoute(route: new Route(
            methods: [Method::GET],
            path: '/admin/super',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => ['admin.super']],
        ));

        // What a frame ahead of this one can write: another route, declaring the
        // "any authenticated user" sentinel.
        $forged = new MatchedRoute(route: new Route(
            methods: [Method::GET],
            path: '/anything',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => ['_authenticated']],
        ));
        $request = $request->withAttribute('_route', $forged);

        $middleware = $middleware->forDispatchedRoute($dispatched);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    /**
     * An unauthenticated refusal is counted, and the label is the route rather
     * than the caller's path.
     *
     * The chain entry this replaced named nobody, said the same thing every
     * time, and keyed its only varying column on attacker-chosen bytes. The
     * counter's label set is bounded by the route table, so a flood is one
     * series however many paths it is spread across — which is the property a
     * per-path chain write could not have at any ceiling.
     */
    #[Test]
    public function anUnauthenticatedRefusalIncrementsOneRouteLabelledSeries(): void
    {
        $anonymous = new AnonymousIdentity();
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($anonymous);

        $metrics = new MetricRegistry();
        $middleware = new AuthorizationMiddleware($this->createStub(GateInterface::class), null, null, $metrics);

        $dispatched = new MatchedRoute(route: new Route(
            methods: [Method::GET],
            path: '/reports/{report}',
            handler: fn(): Response => new Response(),
            name: 'reports.show',
            attributes: ['permissions' => ['reports.view']],
        ));

        $middleware = $middleware->forDispatchedRoute($dispatched);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        foreach (['/reports/1', '/reports/2', '/reports/3'] as $path) {
            $request = $this->createRequest($path);
            $request = $request->withAttribute('_security_context', new SecurityContext($authManager, $request));

            self::assertSame(
                ResponseStatus::Unauthorized->value,
                $middleware->process($request, $handler)->getStatusCode(),
            );
        }

        $counter = $metrics->counter('pulsar_auth_anonymous_denials_total');

        self::assertCount(1, $counter->values(), 'three caller-chosen paths, one series');
        self::assertSame(
            3.0,
            $counter->value(new LabelSet(['reason' => 'unauthenticated', 'route' => 'reports.show'])),
        );
    }

    /**
     * With no metrics registry the refusal still happens; only the count is lost.
     *
     * That is a declared degraded feature rather than a silence — the access log
     * counts the 401 either way — and it must not be able to turn a refusal into
     * a 500.
     */
    #[Test]
    public function anUnauthenticatedRefusalSurvivesAnAbsentMetricsRegistry(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn(new AnonymousIdentity());

        $middleware = new AuthorizationMiddleware($this->createStub(GateInterface::class));

        $request = $this->createRequest('/reports/1');
        $request = $request->withAttribute('_security_context', new SecurityContext($authManager, $request));

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        self::assertSame(
            ResponseStatus::Unauthorized->value,
            $middleware->process($request, $handler)->getStatusCode(),
        );
    }

    private function createJsonRequest(string $path = '/api/resource'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
            headers: ['Accept' => 'application/json'],
        );
    }

    private function createHtmlRequest(string $path = '/dashboard'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
            headers: ['Accept' => 'text/html'],
        );
    }

    private function createSecurityContext(
        IdentityInterface $identity,
        ServerRequest $request,
    ): SecurityContext {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        return new SecurityContext($authManager, $request);
    }

    private function passHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        return $handler;
    }

    #[Test]
    public function returns401JsonWhenNoSecurityContextAndAcceptJson(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createJsonRequest();

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Unauthorized', $body['error']);
        self::assertSame(401, $body['status']);
    }

    #[Test]
    public function returns401PlaintextWhenNoSecurityContextAndAcceptHtml(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createHtmlRequest();

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
        self::assertSame('Unauthorized', (string) $response->getBody());
    }

    #[Test]
    public function returns403JsonWhenPermissionDeniedAndAcceptJson(): void
    {
        $identity = new Identity(
            id: 'user-403',
            displayName: 'Limited User',
            roles: ['viewer'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createJsonRequest('/api/admin/settings');
        $securityContext = $this->createSecurityContext($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $route = new Route(
            methods: [Method::GET],
            path: '/api/admin/settings',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => ['admin.settings']],
        );
        $middleware = $middleware->forDispatchedRoute(new MatchedRoute(route: $route));

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Forbidden', $body['error']);
        self::assertSame(403, $body['status']);
    }

    #[Test]
    public function returns403PlaintextWhenPermissionDeniedAndAcceptHtml(): void
    {
        $identity = new Identity(
            id: 'user-html-403',
            displayName: 'HTML User',
            roles: ['basic'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createHtmlRequest('/admin/panel');
        $securityContext = $this->createSecurityContext($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $route = new Route(
            methods: [Method::GET],
            path: '/admin/panel',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => ['admin.panel']],
        );
        $middleware = $middleware->forDispatchedRoute(new MatchedRoute(route: $route));

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertSame('Forbidden', (string) $response->getBody());
    }

    #[Test]
    public function deniesWhenAuthenticatedWithoutRouteContext(): void
    {
        $identity = new Identity(
            id: 'user-no-perms',
            displayName: 'Regular User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createHtmlRequest('/public/page');
        $securityContext = $this->createSecurityContext($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);
        // Never bound to a dispatch: without route context the required permissions are
        // unknown, so the request must fail closed (denied), not fall through to
        // the handler unchecked — even though the gate here would allow.

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function contextHolderReceivesActorWhenAuthenticated(): void
    {
        $identity = new Identity(
            id: 'user-enriched',
            displayName: 'Enriched User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createStub(GateInterface::class);
        $contextHolder = new RequestContextHolder();
        $contextHolder->set(new RequestContext(
            correlationId: CorrelationId::generate(),
            causationId: CausationId::generate(),
        ));

        $middleware = new AuthorizationMiddleware($gate, contextHolder: $contextHolder);

        $request = $this->createHtmlRequest('/dashboard');
        $securityContext = $this->createSecurityContext($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $middleware->process($request, $this->passHandler());

        self::assertSame('user-enriched', $contextHolder->get()->actor);
    }

    #[Test]
    public function unauthenticatedIdentityReturns401WithoutEnrichingContext(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $contextHolder = new RequestContextHolder();
        $contextHolder->set(new RequestContext(
            correlationId: CorrelationId::generate(),
            causationId: CausationId::generate(),
        ));

        $middleware = new AuthorizationMiddleware($gate, contextHolder: $contextHolder);

        $request = $this->createHtmlRequest('/protected');
        $securityContext = $this->createSecurityContext(new AnonymousIdentity(), $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
        self::assertNull($contextHolder->get()->actor);
    }

    /**
     * An empty permissions list means default-deny, not default-allow:
     * a route that forgot to declare its permissions must not admit every
     * authenticated user. The "any authenticated user" case is expressed
     * by the explicit `_authenticated` sentinel instead.
     */
    #[Test]
    public function rejectsEmptyPermissionsListAsDefaultDeny(): void
    {
        $identity = new Identity(
            id: 'user-empty-perms',
            displayName: 'User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createStub(GateInterface::class);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createHtmlRequest('/page');
        $securityContext = $this->createSecurityContext($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $route = new Route(
            methods: [Method::GET],
            path: '/page',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => []],
        );
        $middleware = $middleware->forDispatchedRoute(new MatchedRoute(route: $route));

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function passesWithExplicitAnyAuthenticatedSentinel(): void
    {
        $identity = new Identity(
            id: 'user-any-auth',
            displayName: 'User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $gate = $this->createStub(GateInterface::class);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createHtmlRequest('/page');
        $securityContext = $this->createSecurityContext($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $route = new Route(
            methods: [Method::GET],
            path: '/page',
            handler: fn(): Response => new Response(),
            attributes: ['permissions' => ['_authenticated']],
        );
        $middleware = $middleware->forDispatchedRoute(new MatchedRoute(route: $route));

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }
}
