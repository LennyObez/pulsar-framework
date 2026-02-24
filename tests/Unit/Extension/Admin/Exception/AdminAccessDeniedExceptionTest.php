<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;

#[CoversClass(AdminAccessDeniedException::class)]
final class AdminAccessDeniedExceptionTest extends TestCase
{
    #[Test]
    public function insufficientRole(): void
    {
        $e = AdminAccessDeniedException::insufficientRole('superadmin');

        self::assertStringContainsString('superadmin', $e->getMessage());
        self::assertStringContainsString('role', $e->getMessage());
    }

    #[Test]
    public function operationDenied(): void
    {
        $e = AdminAccessDeniedException::operationDenied('users', ResourceOperation::Delete);

        self::assertStringContainsString('delete', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
    }

    #[Test]
    public function operationDeniedWithCreate(): void
    {
        $e = AdminAccessDeniedException::operationDenied('posts', ResourceOperation::Create);

        self::assertStringContainsString('create', $e->getMessage());
        self::assertStringContainsString('posts', $e->getMessage());
    }

    #[Test]
    public function operationDeniedWithExport(): void
    {
        $e = AdminAccessDeniedException::operationDenied('orders', ResourceOperation::Export);

        self::assertStringContainsString('export', $e->getMessage());
        self::assertStringContainsString('orders', $e->getMessage());
    }

    #[Test]
    public function operationDeniedWithBulkAction(): void
    {
        $e = AdminAccessDeniedException::operationDenied('items', ResourceOperation::BulkAction);

        self::assertStringContainsString('bulk_action', $e->getMessage());
        self::assertStringContainsString('items', $e->getMessage());
    }

    #[Test]
    public function twoFactorRequired(): void
    {
        $e = AdminAccessDeniedException::twoFactorRequired();

        self::assertStringContainsString('two-factor', $e->getMessage());
    }
}
