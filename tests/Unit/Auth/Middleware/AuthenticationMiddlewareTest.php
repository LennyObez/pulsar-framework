<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

#[CoversClass(AuthenticationMiddleware::class)]
final class AuthenticationMiddlewareTest extends TestCase
{
    #[Test]
    public function attachesSecurityContextAttributeToRequest(): void
    {
        $authManager = $this->createMock(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $capturedRequest = null;
        $handler = function (Request $req) use (&$capturedRequest): Response {
            $capturedRequest = $req;
            return new Response(body: 'OK', status: ResponseStatus::OK);
        };

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        $securityContext = $capturedRequest->attribute('_security_context');
        self::assertInstanceOf(SecurityContext::class, $securityContext);
    }

    #[Test]
    public function attachesAnonymousIdentityAttributeToRequest(): void
    {
        $authManager = $this->createMock(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $capturedRequest = null;
        $handler = function (Request $req) use (&$capturedRequest): Response {
            $capturedRequest = $req;
            return new Response(body: 'OK', status: ResponseStatus::OK);
        };

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        $identity = $capturedRequest->attribute('_identity');
        self::assertInstanceOf(AnonymousIdentity::class, $identity);
    }

    #[Test]
    public function callsNextHandler(): void
    {
        $authManager = $this->createMock(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $handlerCalled = false;
        $handler = function (Request $req) use (&$handlerCalled): Response {
            $handlerCalled = true;
            return new Response(body: 'OK', status: ResponseStatus::OK);
        };

        $response = $middleware->process($request, $handler);

        self::assertTrue($handlerCalled);
        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function doesNotCallAuthManagerAuthenticate(): void
    {
        $authManager = $this->createMock(AuthManagerInterface::class);
        $authManager->expects(self::never())->method('authenticate');

        $middleware = new AuthenticationMiddleware($authManager);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $middleware->process($request, $handler);
    }
}
