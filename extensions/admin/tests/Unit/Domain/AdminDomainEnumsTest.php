<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\AdminPermission;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

#[CoversClass(BulkAction::class)]
final class AdminDomainEnumsTest extends TestCase
{
    // --- ExportFormat ---

    #[Test]
    public function exportFormatCsv(): void
    {
        self::assertSame('csv', ExportFormat::Csv->value);
    }

    #[Test]
    public function exportFormatJson(): void
    {
        self::assertSame('json', ExportFormat::Json->value);
    }

    #[Test]
    public function exportFormatFromString(): void
    {
        self::assertSame(ExportFormat::Csv, ExportFormat::from('csv'));
        self::assertSame(ExportFormat::Json, ExportFormat::from('json'));
    }

    #[Test]
    public function exportFormatTryFromInvalidReturnsNull(): void
    {
        self::assertNull(ExportFormat::tryFrom('xml'));
    }

    // --- FieldType ---

    #[Test]
    public function fieldTypeAllCases(): void
    {
        $cases = FieldType::cases();
        self::assertCount(31, $cases);

        $values = array_map(static fn(FieldType $t): string => $t->value, $cases);

        // Text & Content
        self::assertContains('string', $values);
        self::assertContains('text', $values);
        self::assertContains('rich_text', $values);
        self::assertContains('markdown', $values);
        self::assertContains('code', $values);
        self::assertContains('slug', $values);
        self::assertContains('password', $values);

        // Numeric
        self::assertContains('integer', $values);
        self::assertContains('float', $values);
        self::assertContains('rating', $values);

        // Boolean & Choice
        self::assertContains('boolean', $values);
        self::assertContains('toggle', $values);
        self::assertContains('enum', $values);
        self::assertContains('tags', $values);

        // Temporal
        self::assertContains('date', $values);
        self::assertContains('datetime', $values);
        self::assertContains('time', $values);

        // Contact & Identity
        self::assertContains('email', $values);
        self::assertContains('url', $values);
        self::assertContains('phone', $values);

        // Visual, Media, Structured, Relations, Layout
        self::assertContains('color', $values);
        self::assertContains('json', $values);
        self::assertContains('relation', $values);
        self::assertContains('hidden', $values);
        self::assertContains('computed', $values);
    }

    // --- AdminPermission ---

    #[Test]
    public function adminPermissionAllCases(): void
    {
        $cases = AdminPermission::cases();
        self::assertCount(11, $cases);

        self::assertSame('admin.access', AdminPermission::AccessPanel->value);
        self::assertSame('admin.dashboard', AdminPermission::ViewDashboard->value);
        self::assertSame('admin.resources.manage', AdminPermission::ManageResources->value);
        self::assertSame('admin.export', AdminPermission::ExportData->value);
        self::assertSame('admin.audit.view', AdminPermission::ViewAuditLog->value);
        self::assertSame('admin.settings', AdminPermission::ManageSettings->value);
        self::assertSame('admin.schema.view', AdminPermission::SchemaView->value);
        self::assertSame('admin.schema.create', AdminPermission::SchemaCreate->value);
        self::assertSame('admin.schema.alter', AdminPermission::SchemaAlter->value);
        self::assertSame('admin.schema.drop', AdminPermission::SchemaDrop->value);
        self::assertSame('admin.schema.rename', AdminPermission::SchemaRename->value);
    }

    #[Test]
    public function adminPermissionTryFromInvalid(): void
    {
        self::assertNull(AdminPermission::tryFrom('admin.nonexistent'));
    }

    // --- ResourceOperation ---

    #[Test]
    public function resourceOperationAllCases(): void
    {
        $cases = ResourceOperation::cases();
        self::assertCount(7, $cases);

        self::assertSame('list', ResourceOperation::List->value);
        self::assertSame('view', ResourceOperation::View->value);
        self::assertSame('create', ResourceOperation::Create->value);
        self::assertSame('update', ResourceOperation::Update->value);
        self::assertSame('delete', ResourceOperation::Delete->value);
        self::assertSame('export', ResourceOperation::Export->value);
        self::assertSame('bulk_action', ResourceOperation::BulkAction->value);
    }

    // --- BulkAction ---

    #[Test]
    public function bulkActionConstruction(): void
    {
        $action = new BulkAction(
            name: 'archive',
            label: 'Archive Selected',
            destructive: true,
            requireConfirmation: true,
            icon: 'archive',
        );

        self::assertSame('archive', $action->name);
        self::assertSame('Archive Selected', $action->label);
        self::assertTrue($action->destructive);
        self::assertTrue($action->requireConfirmation);
        self::assertSame('archive', $action->icon);
    }

    #[Test]
    public function bulkActionDefaults(): void
    {
        $action = new BulkAction(
            name: 'publish',
            label: 'Publish',
        );

        self::assertFalse($action->destructive);
        self::assertTrue($action->requireConfirmation);
        self::assertNull($action->icon);
    }
}
