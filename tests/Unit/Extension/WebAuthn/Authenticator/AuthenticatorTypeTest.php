<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Authenticator;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Authenticator\AuthenticatorType;

#[CoversNothing]
final class AuthenticatorTypeTest extends TestCase
{
    #[Test]
    public function platformHasCorrectValue(): void
    {
        self::assertSame('platform', AuthenticatorType::Platform->value);
    }

    #[Test]
    public function crossPlatformHasCorrectValue(): void
    {
        self::assertSame('cross-platform', AuthenticatorType::CrossPlatform->value);
    }

    #[Test]
    public function allCasesExist(): void
    {
        $cases = AuthenticatorType::cases();

        self::assertCount(2, $cases);
    }

    #[Test]
    public function canBeCreatedFromValue(): void
    {
        self::assertSame(AuthenticatorType::Platform, AuthenticatorType::from('platform'));
        self::assertSame(AuthenticatorType::CrossPlatform, AuthenticatorType::from('cross-platform'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        $type = AuthenticatorType::tryFrom('invalid');

        self::assertNull($type);
    }
}
