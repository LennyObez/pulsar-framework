<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Authenticator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Authenticator\AuthenticatorType;

#[CoversClass(AuthenticatorType::class)]
final class AuthenticatorTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, AuthenticatorType}>
     */
    public static function typeProvider(): iterable
    {
        yield 'platform' => ['platform', AuthenticatorType::Platform];
        yield 'cross-platform' => ['cross-platform', AuthenticatorType::CrossPlatform];
    }

    #[Test]
    #[DataProvider('typeProvider')]
    public function fromValueReturnsCorrectCase(string $value, AuthenticatorType $expected): void
    {
        self::assertSame($expected, AuthenticatorType::from($value));
    }

    #[Test]
    public function casesReturnsTwoEntries(): void
    {
        self::assertCount(2, AuthenticatorType::cases());
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(AuthenticatorType::tryFrom('unknown'));
    }

    #[Test]
    public function platformValueStringIsPlatform(): void
    {
        self::assertSame('platform', AuthenticatorType::Platform->value);
    }

    #[Test]
    public function crossPlatformValueStringIsCrossPlatform(): void
    {
        self::assertSame('cross-platform', AuthenticatorType::CrossPlatform->value);
    }
}
