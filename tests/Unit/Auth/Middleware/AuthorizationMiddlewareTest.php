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
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

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
        $matchedRoute = new MatchedRoute(route: $route);
        $request = $request->withAttribute('_route', $matchedRoute);

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
        $matchedRoute = new MatchedRoute(route: $route);
        $request = $request->withAttribute('_route', $matchedRoute);

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
        $matchedRoute = new MatchedRoute(route: $route);
        $request = $request->withAttribute('_route', $matchedRoute);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $middleware->process($request, $handler);
    }
}
