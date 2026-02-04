<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Guard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Guard\TokenGuard;
use Pulsar\Auth\Guard\TokenResolverInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

#[CoversClass(TokenGuard::class)]
final class TokenGuardTest extends TestCase
{
    private TokenResolverInterface $resolver;
    private TokenGuard $guard;

    protected function setUp(): void
    {
        $this->resolver = $this->createStub(TokenResolverInterface::class);
        $this->guard = new TokenGuard($this->resolver);
    }

    #[Test]
    public function nameReturnsToken(): void
    {
        self::assertSame('token', $this->guard->name());
    }

    #[Test]
    public function authenticateReturnsNullWhenNoAuthorizationHeader(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        self::assertNull($this->guard->authenticate($request));
    }

    #[Test]
    public function authenticateReturnsNullWhenAuthorizationHeaderIsNotBearer(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Basic dXNlcjpwYXNz']),
            body: '',
        );

        self::assertNull($this->guard->authenticate($request));
    }

    #[Test]
    public function authenticateReturnsNullWhenBearerTokenIsEmpty(): void
    {
        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Bearer ']),
            body: '',
        );

        self::assertNull($this->guard->authenticate($request));
    }

    #[Test]
    public function authenticateDelegatesToResolverAndReturnsResult(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Token User',
            roles: ['api'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [],
        );

        $resolver = $this->createMock(TokenResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('test-token')
            ->willReturn($identity);

        $guard = new TokenGuard($resolver);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Bearer test-token']),
            body: '',
        );

        $result = $guard->authenticate($request);

        self::assertSame($identity, $result);
    }

    #[Test]
    public function authenticateReturnsNullWhenResolverReturnsNull(): void
    {
        $resolver = $this->createMock(TokenResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('invalid-token')
            ->willReturn(null);

        $guard = new TokenGuard($resolver);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(['Authorization' => 'Bearer invalid-token']),
            body: '',
        );

        self::assertNull($guard->authenticate($request));
    }
}
