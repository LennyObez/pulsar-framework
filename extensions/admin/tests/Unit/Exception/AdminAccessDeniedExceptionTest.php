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
    public function extends_admin_exception(): void
    {
        $exception = AdminAccessDeniedException::insufficientRole('admin');

        self::assertInstanceOf(AdminException::class, $exception);
    }

    #[Test]
    public function insufficient_role_includes_role_name(): void
    {
        $exception = AdminAccessDeniedException::insufficientRole('superadmin');

        self::assertStringContainsString('superadmin', $exception->getMessage());
        self::assertStringContainsString('required', $exception->getMessage());
    }

    #[Test]
    public function operation_denied_includes_resource_and_operation(): void
    {
        $exception = AdminAccessDeniedException::operationDenied('users', ResourceOperation::Delete);

        self::assertStringContainsString('users', $exception->getMessage());
        self::assertStringContainsString('delete', $exception->getMessage());
    }

    #[Test]
    public function two_factor_required_message(): void
    {
        $exception = AdminAccessDeniedException::twoFactorRequired();

        self::assertStringContainsString('two-factor', $exception->getMessage());
    }

    #[Test]
    public function operation_denied_with_each_operation(): void
    {
        foreach (ResourceOperation::cases() as $op) {
            $exception = AdminAccessDeniedException::operationDenied('orders', $op);

            self::assertStringContainsString($op->value, $exception->getMessage());
            self::assertStringContainsString('orders', $exception->getMessage());
        }
    }
}
