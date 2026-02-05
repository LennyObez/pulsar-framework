<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;
use Pulsar\Extension\Admin\Exception\AdminException;

#[CoversClass(AdminException::class)]
#[CoversClass(AdminAccessDeniedException::class)]
final class AdminExceptionTest extends TestCase
{
    #[Test]
    public function disabledProducesCorrectMessage(): void
    {
        $e = AdminException::disabled();

        self::assertStringContainsString('disabled', $e->getMessage());
        self::assertStringContainsString('ADMIN_ENABLED', $e->getMessage());
    }

    #[Test]
    public function invalidConfigurationIncludesDetail(): void
    {
        $e = AdminException::invalidConfiguration('missing route_prefix');

        self::assertStringContainsString('missing route_prefix', $e->getMessage());
        self::assertStringContainsString('Invalid admin configuration', $e->getMessage());
    }

    #[Test]
    public function resourceAlreadyRegisteredIncludesName(): void
    {
        $e = AdminException::resourceAlreadyRegistered('users');

        self::assertStringContainsString('users', $e->getMessage());
        self::assertStringContainsString('already registered', $e->getMessage());
    }

    #[Test]
    public function insufficientRoleIncludesRole(): void
    {
        $e = AdminAccessDeniedException::insufficientRole('admin');

        self::assertStringContainsString('admin', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
    }

    #[Test]
    public function operationDeniedIncludesResourceAndOperation(): void
    {
        $e = AdminAccessDeniedException::operationDenied('users', ResourceOperation::Delete);

        self::assertStringContainsString('delete', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
    }

    #[Test]
    public function twoFactorRequiredMessage(): void
    {
        $e = AdminAccessDeniedException::twoFactorRequired();

        self::assertStringContainsString('two-factor', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
    }

    #[Test]
    public function adminAccessDeniedExtendsAdminException(): void
    {
        $e = AdminAccessDeniedException::insufficientRole('admin');

        self::assertInstanceOf(AdminException::class, $e);
    }
}
