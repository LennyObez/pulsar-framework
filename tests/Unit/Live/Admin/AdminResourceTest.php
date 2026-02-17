<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Live\Admin\AdminResource;
use Pulsar\Live\Admin\BooleanField;
use Pulsar\Live\Admin\DateField;
use Pulsar\Live\Admin\DateRangeFilter;
use Pulsar\Live\Admin\EmailField;
use Pulsar\Live\Admin\SelectField;
use Pulsar\Live\Admin\SelectFilter;
use Pulsar\Live\Admin\TextField;

#[CoversClass(AdminResource::class)]
final class AdminResourceTest extends TestCase
{
    #[Test]
    public function resourceExposesMetadata(): void
    {
        $resource = new TestUserResource();

        self::assertSame('users', $resource->name());
        self::assertSame('User', $resource->label());
        self::assertSame('Users', $resource->pluralLabel());
        self::assertSame('users-icon', $resource->icon());
        self::assertSame('id', $resource->primaryKey());
        self::assertSame('created_at', $resource->defaultSortField());
        self::assertSame('desc', $resource->defaultSortDirection());
        self::assertFalse($resource->auditReads());
    }

    #[Test]
    public function fieldsReturnsFieldArray(): void
    {
        $resource = new TestUserResource();
        $fields = $resource->fields();

        self::assertCount(5, $fields);
        self::assertInstanceOf(FieldDefinition::class, $fields[0]);
    }

    #[Test]
    public function fieldsConvertsToFieldDefinitions(): void
    {
        $resource = new TestUserResource();
        $defs = $resource->fields();

        self::assertCount(5, $defs);
        self::assertSame('name', $defs[0]->name);
        self::assertSame(FieldType::String, $defs[0]->type);
        self::assertTrue($defs[0]->searchable);
    }

    #[Test]
    public function filtersReturnFilterArray(): void
    {
        $resource = new TestUserResource();
        $filters = $resource->filters();

        self::assertCount(2, $filters);
    }

    #[Test]
    public function operationsReturnDefault(): void
    {
        $resource = new TestUserResource();
        $ops = $resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::Create, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertContains(ResourceOperation::Delete, $ops);
    }

    #[Test]
    public function bulkActionsReturnDefault(): void
    {
        $resource = new TestUserResource();
        $actions = $resource->bulkActions();

        self::assertCount(1, $actions);
        self::assertSame('delete', $actions[0]->name);
        self::assertTrue($actions[0]->destructive);
    }

    #[Test]
    public function exportableFieldsExcludesNonExportable(): void
    {
        $resource = new TestUserResource();
        $fields = $resource->exportableFields();

        self::assertNotContains('password', $fields);
        self::assertContains('name', $fields);
        self::assertContains('email', $fields);
    }

    #[Test]
    public function pluralLabelDefaultsToLabelPlusS(): void
    {
        $resource = new MinimalResource();

        self::assertSame('Items', $resource->pluralLabel());
    }

    #[Test]
    public function implementsDataResourceInterface(): void
    {
        $resource = new TestUserResource();

        self::assertInstanceOf(\Pulsar\Extension\Admin\Contracts\DataResourceInterface::class, $resource);
    }
}

/** @internal */
final class TestUserResource extends AdminResource
{
    protected string $resourceName = 'users';
    protected string $resourceLabel = 'User';
    protected string $resourcePluralLabel = 'Users';
    protected string $resourceIcon = 'users-icon';
    protected string $defaultSort = 'created_at';

    public function defineFields(): array
    {
        return [
            TextField::make('name')->required()->searchable(),
            EmailField::make('email')->required()->searchable(),
            SelectField::make('role')->options(['admin', 'user', 'editor']),
            BooleanField::make('is_active'),
            DateField::make('created_at')->readonly()->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('role')->options(['admin', 'user', 'editor']),
            DateRangeFilter::make('created_at'),
        ];
    }
}

/** @internal */
final class MinimalResource extends AdminResource
{
    protected string $resourceName = 'items';
    protected string $resourceLabel = 'Item';

    public function defineFields(): array
    {
        return [TextField::make('name')];
    }
}
