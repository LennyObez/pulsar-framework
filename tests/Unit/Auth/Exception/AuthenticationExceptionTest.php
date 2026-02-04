<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Exception\AuthenticationException;
use RuntimeException;

#[CoversClass(AuthenticationException::class)]
final class AuthenticationExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = AuthenticationException::invalidCredentials();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function invalidCredentialsCreatesCorrectMessage(): void
    {
        $exception = AuthenticationException::invalidCredentials();

        self::assertSame('Invalid credentials', $exception->getMessage());
    }

    #[Test]
    public function unknownGuardCreatesCorrectMessage(): void
    {
        $exception = AuthenticationException::unknownGuard('jwt');

        self::assertSame('Unknown authentication guard: "jwt"', $exception->getMessage());
    }

    #[Test]
    public function noGuardsConfiguredCreatesCorrectMessage(): void
    {
        $exception = AuthenticationException::noGuardsConfigured();

        self::assertSame('No authentication guards are configured', $exception->getMessage());
    }

    #[Test]
    public function sessionExpiredCreatesCorrectMessage(): void
    {
        $exception = AuthenticationException::sessionExpired();

        self::assertSame('Session has expired', $exception->getMessage());
    }

    #[Test]
    public function tokenMissingCreatesCorrectMessage(): void
    {
        $exception = AuthenticationException::tokenMissing();

        self::assertSame('Authentication token is missing', $exception->getMessage());
    }

    #[Test]
    public function tokenInvalidCreatesCorrectMessage(): void
    {
        $exception = AuthenticationException::tokenInvalid();

        self::assertSame('Authentication token is invalid', $exception->getMessage());
    }
}
