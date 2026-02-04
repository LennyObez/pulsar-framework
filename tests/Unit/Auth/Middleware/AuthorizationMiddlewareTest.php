<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    private function createRequest(string $path = '/test'): Request
    {
        return new Request(
            method: Method::GET,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );
    }

    private function createSecurityContextWithIdentity(
        IdentityInterface $identity,
        Request $request,
    ): SecurityContext {
        $authManager = $this->createMock(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        return new SecurityContext($authManager, $request);
    }

    #[Test]
    public function returns401WhenNoSecurityContextOnRequest(): void
    {
        $gate = $this->createMock(GateInterface::class);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createRequest();
        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized, $response->status);
    }

    #[Test]
    public function returns401WhenIdentityIsNotAuthenticated(): void
    {
        $gate = $this->createMock(GateInterface::class);
        $middleware = new AuthorizationMiddleware($gate);

        $request = $this->createRequest();
        $securityContext = $this->createSecurityContextWithIdentity(
            new AnonymousIdentity(),
            $request,
        );
        $request = $request->withAttribute('_security_context', $securityContext);

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized, $response->status);
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

        $gate = $this->createMock(GateInterface::class);
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
        $matchedRoute = new MatchedRoute(route: $route);
        $request = $request->withAttribute('_route', $matchedRoute);

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK, $response->status);
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

        $gate = $this->createMock(GateInterface::class);
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
        $matchedRoute = new MatchedRoute(route: $route);
        $request = $request->withAttribute('_route', $matchedRoute);

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
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
        $matchedRoute = new MatchedRoute(route: $route);
        $request = $request->withAttribute('_route', $matchedRoute);

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $middleware->process($request, $handler);
    }
}
