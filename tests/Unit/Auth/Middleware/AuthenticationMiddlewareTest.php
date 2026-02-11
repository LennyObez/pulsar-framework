<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(AuthenticationMiddleware::class)]
final class AuthenticationMiddlewareTest extends TestCase
{
    #[Test]
    public function attachesSecurityContextAttributeToRequest(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;
                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        $securityContext = $capturedRequest->getAttribute('_security_context');
        self::assertInstanceOf(SecurityContext::class, $securityContext);
    }

    #[Test]
    public function attachesAnonymousIdentityAttributeToRequest(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;
                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        $identity = $capturedRequest->getAttribute('_identity');
        self::assertInstanceOf(AnonymousIdentity::class, $identity);
    }

    #[Test]
    public function callsNextHandler(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $handlerCalled = false;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$handlerCalled): ResponseInterface {
                $handlerCalled = true;
                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            },
        );

        $response = $middleware->process($request, $handler);

        self::assertTrue($handlerCalled);
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function doesNotCallAuthManagerAuthenticate(): void
    {
        $authManager = $this->createMock(AuthManagerInterface::class);
        $authManager->expects(self::never())->method('authenticate');

        $middleware = new AuthenticationMiddleware($authManager);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $middleware->process($request, $handler);
    }

    #[Test]
    public function preservesExistingAuthenticatedIdentityFromDevRouter(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $preAuthIdentity = new Identity(
            id: 'dev-user-1',
            displayName: 'Dev User',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/dev/dashboard',
            attributes: ['identity' => $preAuthIdentity],
        );

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;

                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertSame($preAuthIdentity, $capturedRequest->getAttribute('_identity'));
        self::assertSame($preAuthIdentity, $capturedRequest->getAttribute('identity'));
    }

    #[Test]
    public function nonAuthenticatedExistingIdentityGetsOverwrittenWithAnonymous(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/public/page',
            attributes: ['identity' => new AnonymousIdentity()],
        );

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;

                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('_identity'));
        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('identity'));
    }

    #[Test]
    public function nonIdentityAttributeGetsOverwrittenWithAnonymous(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $middleware = new AuthenticationMiddleware($authManager);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/dashboard',
            attributes: ['identity' => 'not-an-identity-object'],
        );

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;

                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertInstanceOf(AnonymousIdentity::class, $capturedRequest->getAttribute('_identity'));
    }
}
