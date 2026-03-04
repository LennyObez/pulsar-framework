<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Ceremony;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationResult;

#[CoversClass(AuthenticationResult::class)]
final class AuthenticationResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new AuthenticationResult(
            credentialId: 'cred-abc',
            userId: 'user-42',
            signatureCounter: 10,
            userVerified: true,
        );

        self::assertSame('cred-abc', $result->credentialId);
        self::assertSame('user-42', $result->userId);
        self::assertSame(10, $result->signatureCounter);
        self::assertTrue($result->userVerified);
    }

    #[Test]
    public function userNotVerified(): void
    {
        $result = new AuthenticationResult(
            credentialId: 'cred-xyz',
            userId: 'user-99',
            signatureCounter: 0,
            userVerified: false,
        );

        self::assertFalse($result->userVerified);
        self::assertSame(0, $result->signatureCounter);
    }

    #[Test]
    public function signatureCounterCanBeHigh(): void
    {
        $result = new AuthenticationResult(
            credentialId: 'cred-1',
            userId: 'user-1',
            signatureCounter: 999999,
            userVerified: true,
        );

        self::assertSame(999999, $result->signatureCounter);
    }
}
