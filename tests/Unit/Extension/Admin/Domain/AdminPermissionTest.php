<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\AdminPermission;

#[CoversClass(AdminPermission::class)]
final class AdminPermissionTest extends TestCase
{
    #[Test]
    public function accessPanelValue(): void
    {
        self::assertSame('admin.access', AdminPermission::AccessPanel->value);
    }

    #[Test]
    public function viewDashboardValue(): void
    {
        self::assertSame('admin.dashboard', AdminPermission::ViewDashboard->value);
    }

    #[Test]
    public function manageResourcesValue(): void
    {
        self::assertSame('admin.resources.manage', AdminPermission::ManageResources->value);
    }

    #[Test]
    public function exportDataValue(): void
    {
        self::assertSame('admin.export', AdminPermission::ExportData->value);
    }

    #[Test]
    public function viewAuditLogValue(): void
    {
        self::assertSame('admin.audit.view', AdminPermission::ViewAuditLog->value);
    }

    #[Test]
    public function manageSettingsValue(): void
    {
        self::assertSame('admin.settings', AdminPermission::ManageSettings->value);
    }

    #[Test]
    public function schemaViewValue(): void
    {
        self::assertSame('admin.schema.view', AdminPermission::SchemaView->value);
    }

    #[Test]
    public function schemaCreateValue(): void
    {
        self::assertSame('admin.schema.create', AdminPermission::SchemaCreate->value);
    }

    #[Test]
    public function schemaAlterValue(): void
    {
        self::assertSame('admin.schema.alter', AdminPermission::SchemaAlter->value);
    }

    #[Test]
    public function schemaDropValue(): void
    {
        self::assertSame('admin.schema.drop', AdminPermission::SchemaDrop->value);
    }

    #[Test]
    public function schemaRenameValue(): void
    {
        self::assertSame('admin.schema.rename', AdminPermission::SchemaRename->value);
    }

    #[Test]
    public function allCasesArePresent(): void
    {
        self::assertCount(11, AdminPermission::cases());
    }
}
