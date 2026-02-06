<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManager;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(AuthManager::class)]
final class AuthManagerTest extends TestCase
{
    private ServerRequest $request;

    protected function setUp(): void
    {
        $this->request = new ServerRequest(
            method: 'GET',
            uri: '/',
        );
    }

    #[Test]
    public function authenticateReturnsAnonymousIdentityWhenNoGuardsRegistered(): void
    {
        $manager = new AuthManager();

        $identity = $manager->authenticate($this->request);

        self::assertInstanceOf(AnonymousIdentity::class, $identity);
        self::assertFalse($identity->isAuthenticated());
    }

    #[Test]
    public function authenticateReturnsIdentityFromFirstMatchingGuard(): void
    {
        $expectedIdentity = new Identity(
            id: 'user-1',
            displayName: 'First Guard User',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [],
        );

        $guard = $this->createStub(GuardInterface::class);
        $guard->method('name')->willReturn('session');
        $guard->method('authenticate')->willReturn($expectedIdentity);

        $manager = new AuthManager();
        $manager->addGuard($guard);

        $identity = $manager->authenticate($this->request);

        self::assertSame($expectedIdentity, $identity);
    }

    #[Test]
    public function authenticateFallsThroughToSecondGuardIfFirstReturnsNull(): void
    {
        $expectedIdentity = new Identity(
            id: 'user-2',
            displayName: 'Token User',
            roles: ['api'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [],
        );

        $firstGuard = $this->createStub(GuardInterface::class);
        $firstGuard->method('name')->willReturn('session');
        $firstGuard->method('authenticate')->willReturn(null);

        $secondGuard = $this->createStub(GuardInterface::class);
        $secondGuard->method('name')->willReturn('token');
        $secondGuard->method('authenticate')->willReturn($expectedIdentity);

        $manager = new AuthManager();
        $manager->addGuard($firstGuard);
        $manager->addGuard($secondGuard);

        $identity = $manager->authenticate($this->request);

        self::assertSame($expectedIdentity, $identity);
    }

    #[Test]
    public function guardReturnsRegisteredGuardByName(): void
    {
        $guard = $this->createStub(GuardInterface::class);
        $guard->method('name')->willReturn('session');

        $manager = new AuthManager();
        $manager->addGuard($guard);

        self::assertSame($guard, $manager->guard('session'));
    }

    #[Test]
    public function guardThrowsAuthenticationExceptionForUnknownGuard(): void
    {
        $manager = new AuthManager();

        $this->expectException(AuthenticationException::class);

        $manager->guard('nonexistent');
    }

    #[Test]
    public function defaultGuardReturnsConfiguredDefaultName(): void
    {
        $manager = new AuthManager(defaultGuardName: 'token');

        self::assertSame('token', $manager->defaultGuard());
    }
}
