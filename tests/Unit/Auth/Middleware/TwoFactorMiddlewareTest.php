<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\TwoFactorMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

use function json_decode;

#[CoversClass(TwoFactorMiddleware::class)]
final class TwoFactorMiddlewareTest extends TestCase
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
    public function returns403WhenNoSecurityContextOnRequest(): void
    {
        $middleware = new TwoFactorMiddleware();

        $request = $this->createRequest();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughWhenIdentityHasTwoFactorDisabled(): void
    {
        $middleware = new TwoFactorMiddleware();

        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Disabled,
        );

        $request = $this->createRequest();
        $securityContext = $this->createSecurityContextWithIdentity($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughWhenIdentityHasTwoFactorVerified(): void
    {
        $middleware = new TwoFactorMiddleware();

        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Verified,
        );

        $request = $this->createRequest();
        $securityContext = $this->createSecurityContextWithIdentity($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function returns403WhenIdentityHasTwoFactorPending(): void
    {
        $middleware = new TwoFactorMiddleware();

        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Pending,
        );

        $request = $this->createRequest();
        $securityContext = $this->createSecurityContextWithIdentity($identity, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughForAnonymousIdentity(): void
    {
        $middleware = new TwoFactorMiddleware();

        $request = $this->createRequest();
        $securityContext = $this->createSecurityContextWithIdentity(
            new AnonymousIdentity(),
            $request,
        );
        $request = $request->withAttribute('_security_context', $securityContext);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function returns403JsonWhenNoSecurityContextAndAcceptJson(): void
    {
        $middleware = new TwoFactorMiddleware();
        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/protected',
            headers: ['Accept' => 'application/json'],
        );

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Two-factor authentication verification required', $body['error']);
        self::assertSame(403, $body['status']);
    }

    #[Test]
    public function returns403PlaintextWhenNoSecurityContextAndAcceptHtml(): void
    {
        $middleware = new TwoFactorMiddleware();
        $request = new ServerRequest(
            method: 'GET',
            uri: '/protected',
            headers: ['Accept' => 'text/html'],
        );

        $response = $middleware->process($request, $this->passHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertSame('Two-factor authentication verification required', (string) $response->getBody());
    }

    private function passHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: ResponseStatus::OK->value, body: 'OK'));

        return $handler;
    }
}
