<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Forum\Admin\ForumCategoryResource;

#[CoversClass(ForumCategoryResource::class)]
final class ForumCategoryResourceTest extends TestCase
{
    private ForumCategoryResource $resource;

    protected function setUp(): void
    {
        $this->resource = new ForumCategoryResource();
    }

    #[Test]
    public function implementsDataResourceInterface(): void
    {
        self::assertInstanceOf(DataResourceInterface::class, $this->resource);
    }

    #[Test]
    public function nameReturnsCategoriesIdentifier(): void
    {
        self::assertSame('forum_categories', $this->resource->name());
    }

    #[Test]
    public function labelReturnsHumanReadableLabel(): void
    {
        self::assertSame('Forum Category', $this->resource->label());
    }

    #[Test]
    public function pluralLabelReturnsPlural(): void
    {
        self::assertSame('Forum Categories', $this->resource->pluralLabel());
    }

    #[Test]
    public function iconReturnsFolderIcon(): void
    {
        self::assertSame('folder', $this->resource->icon());
    }

    #[Test]
    public function fieldsContainsExpectedFieldNames(): void
    {
        $fields = $this->resource->fields();
        $names = array_map(static fn($f) => $f->name, $fields);

        self::assertContains('id', $names);
        self::assertContains('name', $names);
        self::assertContains('slug', $names);
        self::assertContains('description', $names);
        self::assertContains('parent_id', $names);
        self::assertContains('sort_order', $names);
        self::assertContains('is_locked', $names);
        self::assertContains('created_at', $names);
    }

    #[Test]
    public function nameFieldIsSearchable(): void
    {
        $fields = $this->resource->fields();
        $nameField = $this->findField($fields, 'name');

        self::assertTrue($nameField->searchable);
    }

    #[Test]
    public function parentIdFieldIsRelationToSelf(): void
    {
        $fields = $this->resource->fields();
        $parentField = $this->findField($fields, 'parent_id');

        self::assertSame(FieldType::Relation, $parentField->type);
        self::assertSame('forum_categories', $parentField->relationResource);
    }

    #[Test]
    public function operationsIncludeFullCrud(): void
    {
        $ops = $this->resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Create, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertContains(ResourceOperation::Delete, $ops);
    }

    #[Test]
    public function bulkActionsIncludeLockAndUnlock(): void
    {
        $actions = $this->resource->bulkActions();
        $names = array_map(static fn($a) => $a->name, $actions);

        self::assertContains('lock', $names);
        self::assertContains('unlock', $names);
    }

    #[Test]
    public function exportableFieldsContainsAllKeyFields(): void
    {
        $exportable = $this->resource->exportableFields();

        self::assertContains('id', $exportable);
        self::assertContains('name', $exportable);
        self::assertContains('slug', $exportable);
    }

    #[Test]
    public function auditReadsIsFalse(): void
    {
        self::assertFalse($this->resource->auditReads());
    }

    #[Test]
    public function primaryKeyIsId(): void
    {
        self::assertSame('id', $this->resource->primaryKey());
    }

    #[Test]
    public function defaultSortIsBySortOrderAscending(): void
    {
        self::assertSame('sort_order', $this->resource->defaultSortField());
        self::assertSame('asc', $this->resource->defaultSortDirection());
    }

    /** @param list<\Pulsar\Extension\Admin\Domain\FieldDefinition> $fields */
    private function findField(array $fields, string $name): \Pulsar\Extension\Admin\Domain\FieldDefinition
    {
        foreach ($fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        self::fail("Field '{$name}' not found");
    }
}
