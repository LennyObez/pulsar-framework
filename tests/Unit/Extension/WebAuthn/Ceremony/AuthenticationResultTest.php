<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Ceremony;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationResult;

#[CoversClass(AuthenticationResult::class)]
final class AuthenticationResultTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $result = new AuthenticationResult(
            credentialId: 'cred-001',
            userId: 'user-42',
            signatureCounter: 5,
            userVerified: true,
        );

        self::assertSame('cred-001', $result->credentialId);
        self::assertSame('user-42', $result->userId);
        self::assertSame(5, $result->signatureCounter);
        self::assertTrue($result->userVerified);
    }

    #[Test]
    public function userNotVerified(): void
    {
        $result = new AuthenticationResult(
            credentialId: 'cred-002',
            userId: 'user-42',
            signatureCounter: 10,
            userVerified: false,
        );

        self::assertFalse($result->userVerified);
    }

    #[Test]
    public function zeroSignatureCounter(): void
    {
        $result = new AuthenticationResult(
            credentialId: 'cred-003',
            userId: 'user-42',
            signatureCounter: 0,
            userVerified: true,
        );

        self::assertSame(0, $result->signatureCounter);
    }
}
