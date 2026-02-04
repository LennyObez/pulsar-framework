<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\TwoFactorMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

#[CoversClass(TwoFactorMiddleware::class)]
final class TwoFactorMiddlewareTest extends TestCase
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
    public function returns403WhenNoSecurityContextOnRequest(): void
    {
        $middleware = new TwoFactorMiddleware();

        $request = $this->createRequest();
        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
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

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK, $response->status);
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

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK, $response->status);
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

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
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

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK, $response->status);
    }
}
