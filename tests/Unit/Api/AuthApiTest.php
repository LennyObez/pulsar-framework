<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Guard\TokenResolverInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Auth\TwoFactor\TwoFactorManagerInterface;

#[CoversClass(Api::class)]
final class AuthApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function authManagerInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(AuthManagerInterface::class);
    }

    #[Test]
    public function guardInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(GuardInterface::class);
    }

    #[Test]
    public function tokenResolverInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(TokenResolverInterface::class);
    }

    #[Test]
    public function identityInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(IdentityInterface::class);
    }

    #[Test]
    public function passwordHasherInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(PasswordHasherInterface::class);
    }

    #[Test]
    public function twoFactorManagerInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(TwoFactorManagerInterface::class);
    }

    #[Test]
    public function gateInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(GateInterface::class);
    }

    #[Test]
    public function policyInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(PolicyInterface::class);
    }

    #[Test]
    public function roleRegistryInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(RoleRegistryInterface::class);
    }

    #[Test]
    public function identityValueObjectsArePublicApi(): void
    {
        self::assertHasApiAttribute(Identity::class);
        self::assertHasApiAttribute(AnonymousIdentity::class);
        self::assertClassIsReadonly(Identity::class);
        self::assertClassIsReadonly(AnonymousIdentity::class);
    }

    #[Test]
    public function roleAndPermissionArePublicApi(): void
    {
        self::assertHasApiAttribute(Role::class);
        self::assertHasApiAttribute(Permission::class);
        self::assertHasApiAttribute(PolicyContext::class);
        self::assertClassIsReadonly(Role::class);
        self::assertClassIsReadonly(Permission::class);
        self::assertClassIsReadonly(PolicyContext::class);
    }

    #[Test]
    public function twoFactorStatusIsPublicApi(): void
    {
        self::assertHasApiAttribute(TwoFactorStatus::class);
    }

    #[Test]
    public function authExceptionsArePublicApi(): void
    {
        self::assertHasApiAttribute(AuthenticationException::class);
        self::assertHasApiAttribute(AuthorizationException::class);
    }
}
