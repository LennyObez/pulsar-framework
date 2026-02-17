<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\AdminPermission;

#[CoversClass(AdminPermission::class)]
final class AdminPermissionTest extends TestCase
{
    #[Test]
    #[DataProvider('permissionCaseProvider')]
    public function permission_has_correct_value(AdminPermission $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{AdminPermission, string}>
     */
    public static function permissionCaseProvider(): iterable
    {
        yield 'AccessPanel' => [AdminPermission::AccessPanel, 'admin.access'];
        yield 'ViewDashboard' => [AdminPermission::ViewDashboard, 'admin.dashboard'];
        yield 'ManageResources' => [AdminPermission::ManageResources, 'admin.resources.manage'];
        yield 'ExportData' => [AdminPermission::ExportData, 'admin.export'];
        yield 'ViewAuditLog' => [AdminPermission::ViewAuditLog, 'admin.audit.view'];
        yield 'ManageSettings' => [AdminPermission::ManageSettings, 'admin.settings'];
        yield 'SchemaView' => [AdminPermission::SchemaView, 'admin.schema.view'];
        yield 'SchemaCreate' => [AdminPermission::SchemaCreate, 'admin.schema.create'];
        yield 'SchemaAlter' => [AdminPermission::SchemaAlter, 'admin.schema.alter'];
        yield 'SchemaDrop' => [AdminPermission::SchemaDrop, 'admin.schema.drop'];
        yield 'SchemaRename' => [AdminPermission::SchemaRename, 'admin.schema.rename'];
    }

    #[Test]
    public function all_cases_are_backed_by_string(): void
    {
        $cases = AdminPermission::cases();

        self::assertCount(11, $cases);

        foreach ($cases as $case) {
            self::assertIsString($case->value);
            self::assertStringStartsWith('admin.', $case->value);
        }
    }

    #[Test]
    public function from_creates_valid_permission(): void
    {
        $permission = AdminPermission::from('admin.access');

        self::assertSame(AdminPermission::AccessPanel, $permission);
    }

    #[Test]
    public function try_from_returns_null_for_unknown_value(): void
    {
        $result = AdminPermission::tryFrom('admin.nonexistent');

        self::assertNull($result);
    }
}
