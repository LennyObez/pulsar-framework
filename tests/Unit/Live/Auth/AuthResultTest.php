<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Auth\AuthResult;

#[CoversClass(AuthResult::class)]
final class AuthResultTest extends TestCase
{
    #[Test]
    public function okCreatesSuccessResult(): void
    {
        $result = AuthResult::ok('/dashboard');

        self::assertTrue($result->success);
        self::assertNull($result->error);
        self::assertFalse($result->requiresMfa);
        self::assertNull($result->identityId);
        self::assertSame('/dashboard', $result->redirectUrl);
    }

    #[Test]
    public function okWithoutRedirect(): void
    {
        $result = AuthResult::ok();

        self::assertTrue($result->success);
        self::assertNull($result->redirectUrl);
    }

    #[Test]
    public function failedCreatesErrorResult(): void
    {
        $result = AuthResult::failed('Invalid credentials');

        self::assertFalse($result->success);
        self::assertSame('Invalid credentials', $result->error);
        self::assertFalse($result->requiresMfa);
    }

    #[Test]
    public function mfaRequiredCreatesMfaResult(): void
    {
        $result = AuthResult::mfaRequired('user-123');

        self::assertFalse($result->success);
        self::assertTrue($result->requiresMfa);
        self::assertSame('user-123', $result->identityId);
    }
}
