<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Exception\AuthorizationException;
use RuntimeException;

#[CoversClass(AuthorizationException::class)]
final class AuthorizationExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = AuthorizationException::unauthenticated();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function permissionDeniedCreatesCorrectMessage(): void
    {
        $exception = AuthorizationException::permissionDenied('users.delete');

        self::assertSame('Permission denied: "users.delete"', $exception->getMessage());
    }

    #[Test]
    public function roleNotFoundCreatesCorrectMessage(): void
    {
        $exception = AuthorizationException::roleNotFound('superadmin');

        self::assertSame('Role not found: "superadmin"', $exception->getMessage());
    }

    #[Test]
    public function policyDeniedCreatesCorrectMessage(): void
    {
        $exception = AuthorizationException::policyDenied('PostPolicy');

        self::assertSame('Policy denied access: "PostPolicy"', $exception->getMessage());
    }

    #[Test]
    public function unauthenticatedCreatesCorrectMessage(): void
    {
        $exception = AuthorizationException::unauthenticated();

        self::assertSame('Authentication is required', $exception->getMessage());
    }

    #[Test]
    public function twoFactorRequiredCreatesCorrectMessage(): void
    {
        $exception = AuthorizationException::twoFactorRequired();

        self::assertSame('Two-factor authentication verification is required', $exception->getMessage());
    }
}
