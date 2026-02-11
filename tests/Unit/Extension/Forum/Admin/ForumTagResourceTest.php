<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Forum\Admin\ForumTagResource;

#[CoversClass(ForumTagResource::class)]
final class ForumTagResourceTest extends TestCase
{
    private ForumTagResource $resource;

    protected function setUp(): void
    {
        $this->resource = new ForumTagResource();
    }

    #[Test]
    public function nameReturnsCorrectIdentifier(): void
    {
        self::assertSame('forum_tags', $this->resource->name());
    }

    #[Test]
    public function labelsAreCorrect(): void
    {
        self::assertSame('Forum Tag', $this->resource->label());
        self::assertSame('Forum Tags', $this->resource->pluralLabel());
    }

    #[Test]
    public function iconReturnsTag(): void
    {
        self::assertSame('tag', $this->resource->icon());
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
    public function bulkActionsIsEmpty(): void
    {
        self::assertSame([], $this->resource->bulkActions());
    }

    #[Test]
    public function auditReadsIsFalse(): void
    {
        self::assertFalse($this->resource->auditReads());
    }

    #[Test]
    public function defaultSortIsUsageCountDescending(): void
    {
        self::assertSame('usage_count', $this->resource->defaultSortField());
        self::assertSame('desc', $this->resource->defaultSortDirection());
    }

    #[Test]
    public function nameFieldIsSearchable(): void
    {
        $fields = $this->resource->fields();
        foreach ($fields as $field) {
            if ($field->name === 'name') {
                self::assertTrue($field->searchable);

                return;
            }
        }

        self::fail('Field name not found');
    }

    #[Test]
    public function exportableFieldsContainsAllKeyFields(): void
    {
        $exportable = $this->resource->exportableFields();

        self::assertContains('id', $exportable);
        self::assertContains('name', $exportable);
        self::assertContains('slug', $exportable);
        self::assertContains('usage_count', $exportable);
    }

    #[Test]
    public function primaryKeyIsId(): void
    {
        self::assertSame('id', $this->resource->primaryKey());
    }
}
