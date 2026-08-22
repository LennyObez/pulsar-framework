<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;
use Pulsar\Extension\Admin\Exception\AdminException;

#[CoversClass(AdminAccessDeniedException::class)]
final class AdminAccessDeniedExceptionTest extends TestCase
{
    #[Test]
    public function extendsAdminException(): void
    {
        $e = AdminAccessDeniedException::insufficientRole('admin');

        self::assertInstanceOf(AdminException::class, $e);
    }

    #[Test]
    public function insufficientRoleIncludesRoleName(): void
    {
        $e = AdminAccessDeniedException::insufficientRole('superadmin');

        self::assertStringContainsString('superadmin', $e->getMessage());
        self::assertStringContainsString('role', $e->getMessage());
        self::assertStringContainsString('required', $e->getMessage());
    }

    #[Test]
    public function operationDeniedIncludesResourceAndOperation(): void
    {
        $e = AdminAccessDeniedException::operationDenied('users', ResourceOperation::Delete);

        self::assertStringContainsString('delete', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
    }

    #[Test]
    public function operationDeniedCoversEveryOperation(): void
    {
        foreach (ResourceOperation::cases() as $operation) {
            $e = AdminAccessDeniedException::operationDenied('orders', $operation);

            self::assertStringContainsString($operation->value, $e->getMessage());
            self::assertStringContainsString('orders', $e->getMessage());
        }
    }

    #[Test]
    public function twoFactorRequiredMessage(): void
    {
        $e = AdminAccessDeniedException::twoFactorRequired();

        self::assertStringContainsString('two-factor', $e->getMessage());
    }
}
