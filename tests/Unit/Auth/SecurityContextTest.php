<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(SecurityContext::class)]
final class SecurityContextTest extends TestCase
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
    public function identityCallsAuthManagerAuthenticateOnlyOnFirstAccess(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test User',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [],
        );

        $authManager = $this->createMock(AuthManagerInterface::class);
        $authManager->expects(self::once())
            ->method('authenticate')
            ->with($this->request)
            ->willReturn($identity);

        $context = new SecurityContext($authManager, $this->request);

        // First call triggers authenticate
        $result = $context->identity();
        self::assertSame($identity, $result);

        // Second call returns cached identity without calling authenticate again
        $result2 = $context->identity();
        self::assertSame($identity, $result2);
    }

    #[Test]
    public function identityReturnsCachedIdentityOnSubsequentCalls(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Cached User',
            roles: [],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [],
        );

        $authManager = $this->createMock(AuthManagerInterface::class);
        $authManager->expects(self::once())
            ->method('authenticate')
            ->willReturn($identity);

        $context = new SecurityContext($authManager, $this->request);

        $first = $context->identity();
        $second = $context->identity();
        $third = $context->identity();

        self::assertSame($first, $second);
        self::assertSame($second, $third);
    }

    #[Test]
    public function isAuthenticatedReturnsTrueForAuthenticatedIdentity(): void
    {
        $identity = new Identity(
            id: 'user-1',
            displayName: 'Authenticated User',
            roles: ['user'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: [],
        );

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        $context = new SecurityContext($authManager, $this->request);

        self::assertTrue($context->isAuthenticated());
    }

    #[Test]
    public function isAuthenticatedReturnsFalseForAnonymousIdentity(): void
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn(new AnonymousIdentity());

        $context = new SecurityContext($authManager, $this->request);

        self::assertFalse($context->isAuthenticated());
    }
}
