<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\TwoFactorStatus;

#[CoversClass(AnonymousIdentity::class)]
final class AnonymousIdentityTest extends TestCase
{
    #[Test]
    public function isAuthenticatedReturnsFalse(): void
    {
        $identity = new AnonymousIdentity();

        self::assertFalse($identity->isAuthenticated());
    }

    #[Test]
    public function idReturnsEmptyString(): void
    {
        $identity = new AnonymousIdentity();

        self::assertSame('', $identity->id());
    }

    #[Test]
    public function displayNameReturnsAnonymous(): void
    {
        $identity = new AnonymousIdentity();

        self::assertSame('Anonymous', $identity->displayName());
    }

    #[Test]
    public function rolesReturnsEmptyArray(): void
    {
        $identity = new AnonymousIdentity();

        self::assertSame([], $identity->roles());
    }

    #[Test]
    public function hasRoleAlwaysReturnsFalse(): void
    {
        $identity = new AnonymousIdentity();

        self::assertFalse($identity->hasRole('admin'));
        self::assertFalse($identity->hasRole('editor'));
        self::assertFalse($identity->hasRole('viewer'));
        self::assertFalse($identity->hasRole(''));
    }

    #[Test]
    public function twoFactorStatusReturnsDisabled(): void
    {
        $identity = new AnonymousIdentity();

        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
    }

    #[Test]
    public function attributesReturnsEmptyArray(): void
    {
        $identity = new AnonymousIdentity();

        self::assertSame([], $identity->attributes());
    }

    #[Test]
    public function attributeAlwaysReturnsDefault(): void
    {
        $identity = new AnonymousIdentity();

        self::assertNull($identity->attribute('email'));
        self::assertSame('fallback', $identity->attribute('email', 'fallback'));
        self::assertSame(42, $identity->attribute('age', 42));
    }
}
