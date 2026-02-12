<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\AdminPermission;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Domain\SavedView;

use function count;

final class AdminDomainTest extends TestCase
{
    #[Test]
    public function action_result_success(): void
    {
        $result = ActionResult::success('Created', ['id' => '123']);

        self::assertTrue($result->success);
        self::assertSame('Created', $result->message);
        self::assertSame(['id' => '123'], $result->metadata);
    }

    #[Test]
    public function action_result_failure(): void
    {
        $result = ActionResult::failure('Validation failed');

        self::assertFalse($result->success);
        self::assertSame('Validation failed', $result->message);
    }

    #[Test]
    public function action_result_default_metadata(): void
    {
        $result = ActionResult::success('OK');

        self::assertSame([], $result->metadata);
    }

    #[Test]
    public function admin_permission_values(): void
    {
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
    public function resource_operation_values(): void
    {
        self::assertSame('list', ResourceOperation::List->value);
        self::assertSame('view', ResourceOperation::View->value);
        self::assertSame('create', ResourceOperation::Create->value);
        self::assertSame('update', ResourceOperation::Update->value);
        self::assertSame('delete', ResourceOperation::Delete->value);
        self::assertSame('export', ResourceOperation::Export->value);
        self::assertSame('bulk_action', ResourceOperation::BulkAction->value);
    }

    #[Test]
    public function export_format_values(): void
    {
        self::assertSame('csv', ExportFormat::Csv->value);
        self::assertSame('json', ExportFormat::Json->value);
    }

    #[Test]
    public function field_type_covers_all_types(): void
    {
        $types = FieldType::cases();

        self::assertGreaterThanOrEqual(12, count($types));
        self::assertSame('string', FieldType::String->value);
        self::assertSame('text', FieldType::Text->value);
        self::assertSame('integer', FieldType::Integer->value);
        self::assertSame('float', FieldType::Float->value);
        self::assertSame('boolean', FieldType::Boolean->value);
        self::assertSame('date', FieldType::Date->value);
        self::assertSame('datetime', FieldType::DateTime->value);
        self::assertSame('email', FieldType::Email->value);
        self::assertSame('url', FieldType::Url->value);
        self::assertSame('json', FieldType::Json->value);
        self::assertSame('enum', FieldType::Enum->value);
        self::assertSame('relation', FieldType::Relation->value);
    }

    #[Test]
    public function field_definition_construction_with_defaults(): void
    {
        $field = new FieldDefinition(
            name: 'title',
            type: FieldType::String,
            label: 'Title',
        );

        self::assertSame('title', $field->name);
        self::assertSame(FieldType::String, $field->type);
        self::assertSame('Title', $field->label);
        self::assertFalse($field->sortable);
        self::assertFalse($field->filterable);
        self::assertFalse($field->searchable);
        self::assertFalse($field->redacted);
        self::assertTrue($field->exportable);
        self::assertTrue($field->editable);
        self::assertTrue($field->visibleOnList);
        self::assertTrue($field->visibleOnDetail);
        self::assertTrue($field->visibleOnForm);
        self::assertSame([], $field->rules);
        self::assertSame([], $field->enumValues);
        self::assertNull($field->relationResource);
        self::assertNull($field->placeholder);
        self::assertNull($field->helpText);
    }

    #[Test]
    public function field_definition_construction_with_all_options(): void
    {
        $field = new FieldDefinition(
            name: 'ssn',
            type: FieldType::String,
            label: 'SSN',
            sortable: true,
            filterable: true,
            searchable: true,
            redacted: true,
            exportable: false,
            editable: false,
            visibleOnList: false,
            visibleOnDetail: true,
            visibleOnForm: false,
            rules: [new \Pulsar\Extension\Admin\Domain\ValidationRule('required')],
            placeholder: '000-00-0000',
            helpText: 'Social Security Number',
        );

        self::assertTrue($field->sortable);
        self::assertTrue($field->redacted);
        self::assertFalse($field->exportable);
        self::assertFalse($field->editable);
        self::assertCount(1, $field->rules);
        self::assertSame('000-00-0000', $field->placeholder);
    }

    #[Test]
    public function saved_view_construction(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'Active Users',
            filters: ['status' => 'active'],
            sort: ['name' => 'asc'],
            perPage: 25,
            createdBy: 'admin-1',
            isDefault: true,
            createdAt: 1700000000,
        );

        self::assertSame('v1', $view->id);
        self::assertSame('users', $view->resourceName);
        self::assertSame('Active Users', $view->label);
        self::assertSame(['status' => 'active'], $view->filters);
        self::assertSame(['name' => 'asc'], $view->sort);
        self::assertSame(25, $view->perPage);
        self::assertSame('admin-1', $view->createdBy);
        self::assertTrue($view->isDefault);
        self::assertSame(1700000000, $view->createdAt);
    }

    #[Test]
    public function saved_view_defaults(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'posts',
            label: 'All Posts',
            filters: [],
            sort: [],
            perPage: 10,
            createdBy: 'admin-1',
        );

        self::assertFalse($view->isDefault);
        self::assertSame(0, $view->createdAt);
    }
}
