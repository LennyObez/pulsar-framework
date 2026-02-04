<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManager;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Guard\TokenGuard;
use Pulsar\Auth\Guard\TokenResolverInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Session\SessionInterface;

#[CoversClass(AuthManager::class)]
#[CoversClass(SessionGuard::class)]
#[CoversClass(TokenGuard::class)]
#[CoversClass(AuthenticationMiddleware::class)]
#[CoversClass(SecurityContext::class)]
final class AuthenticationFlowTest extends TestCase
{
    #[Test]
    public function sessionLoginAndAuthenticateRoundtrip(): void
    {
        $sessionData = [];
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('regenerate');
        $session->method('set')->willReturnCallback(
            function (string $key, mixed $value) use (&$sessionData): void {
                $sessionData[$key] = $value;
            },
        );
        $session->method('has')->willReturnCallback(
            function (string $key) use (&$sessionData): bool {
                return isset($sessionData[$key]);
            },
        );
        $session->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) use (&$sessionData): mixed {
                return $sessionData[$key] ?? $default;
            },
        );

        $guard = new SessionGuard($session);
        $manager = new AuthManager();
        $manager->addGuard($guard);

        $identity = new Identity(
            id: 'user-42',
            displayName: 'Jane Doe',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: ['email' => 'jane@example.com'],
        );

        // Login stores identity in session
        $guard->login($identity);

        // Authenticate retrieves it
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $result = $manager->authenticate($request);

        self::assertInstanceOf(Identity::class, $result);
        self::assertSame('user-42', $result->id());
        self::assertSame('Jane Doe', $result->displayName());
        self::assertSame(['admin'], $result->roles());
        self::assertSame('jane@example.com', $result->attribute('email'));
    }

    #[Test]
    public function tokenGuardAuthenticatesFromBearerHeader(): void
    {
        $expectedIdentity = new Identity(
            id: 'api-user-1',
            displayName: 'API User',
            roles: ['api'],
        );

        $resolver = $this->createStub(TokenResolverInterface::class);
        $resolver->method('resolve')
            ->with('valid-api-token')
            ->willReturn($expectedIdentity);

        $guard = new TokenGuard($resolver);
        $manager = new AuthManager();
        $manager->addGuard($guard);

        $request = new Request(
            method: Method::GET,
            uri: '/api/data',
            path: '/api/data',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Bearer valid-api-token']),
            body: '',
        );

        $result = $manager->authenticate($request);

        self::assertSame($expectedIdentity, $result);
    }

    #[Test]
    public function authenticationMiddlewareSetsLazyContextThatResolvesOnDemand(): void
    {
        $expectedIdentity = new Identity(
            id: 'user-1',
            displayName: 'Test',
            roles: ['user'],
        );

        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn($expectedIdentity->toArray());

        $guard = new SessionGuard($session);
        $manager = new AuthManager();
        $manager->addGuard($guard);

        $middleware = new AuthenticationMiddleware($manager);

        $request = new Request(
            method: Method::GET,
            uri: '/dashboard',
            path: '/dashboard',
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

        // Default identity is anonymous
        $defaultIdentity = $capturedRequest->attribute('_identity');
        self::assertInstanceOf(AnonymousIdentity::class, $defaultIdentity);

        // SecurityContext resolves to real identity on access
        /** @var SecurityContext $context */
        $context = $capturedRequest->attribute('_security_context');
        self::assertInstanceOf(SecurityContext::class, $context);

        $resolved = $context->identity();
        self::assertSame('user-1', $resolved->id());
        self::assertTrue($context->isAuthenticated());
    }

    #[Test]
    public function multiGuardFallbackSessionThenToken(): void
    {
        $tokenIdentity = new Identity(
            id: 'token-user',
            displayName: 'Token User',
            roles: ['api'],
        );

        // Session guard returns null (no session data)
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('has')->willReturn(false);

        $sessionGuard = new SessionGuard($session);

        // Token guard resolves the token
        $resolver = $this->createStub(TokenResolverInterface::class);
        $resolver->method('resolve')
            ->with('my-token')
            ->willReturn($tokenIdentity);

        $tokenGuard = new TokenGuard($resolver);

        $manager = new AuthManager();
        $manager->addGuard($sessionGuard);
        $manager->addGuard($tokenGuard);

        $request = new Request(
            method: Method::GET,
            uri: '/api/resource',
            path: '/api/resource',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Bearer my-token']),
            body: '',
        );

        $result = $manager->authenticate($request);

        self::assertSame('token-user', $result->id());
    }

    #[Test]
    public function logoutClearsSessionIdentity(): void
    {
        $sessionData = [];
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('regenerate');
        $session->method('set')->willReturnCallback(
            function (string $key, mixed $value) use (&$sessionData): void {
                $sessionData[$key] = $value;
            },
        );
        $session->method('remove')->willReturnCallback(
            function (string $key) use (&$sessionData): void {
                unset($sessionData[$key]);
            },
        );
        $session->method('has')->willReturnCallback(
            function (string $key) use (&$sessionData): bool {
                return isset($sessionData[$key]);
            },
        );
        $session->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) use (&$sessionData): mixed {
                return $sessionData[$key] ?? $default;
            },
        );

        $guard = new SessionGuard($session);
        $manager = new AuthManager();
        $manager->addGuard($guard);

        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test',
            roles: ['user'],
        );

        $guard->login($identity);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        // Before logout
        $result = $manager->authenticate($request);
        self::assertSame('user-1', $result->id());

        // After logout
        $guard->logout();
        $result = $manager->authenticate($request);
        self::assertInstanceOf(AnonymousIdentity::class, $result);
    }
}
