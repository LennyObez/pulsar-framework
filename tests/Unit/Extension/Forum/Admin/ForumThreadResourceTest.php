<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Forum\Admin\ForumThreadResource;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;

use function array_map;

#[CoversClass(ForumThreadResource::class)]
final class ForumThreadResourceTest extends TestCase
{
    private ForumThreadResource $resource;

    protected function setUp(): void
    {
        $this->resource = new ForumThreadResource();
    }

    #[Test]
    public function implementsDataResourceInterface(): void
    {
        self::assertInstanceOf(DataResourceInterface::class, $this->resource);
    }

    #[Test]
    public function nameReturnsCorrectIdentifier(): void
    {
        self::assertSame('forum_threads', $this->resource->name());
    }

    #[Test]
    public function labelsAreCorrect(): void
    {
        self::assertSame('Forum Thread', $this->resource->label());
        self::assertSame('Forum Threads', $this->resource->pluralLabel());
    }

    #[Test]
    public function fieldsContainTypeEnumWithCorrectValues(): void
    {
        $fields = $this->resource->fields();
        $typeField = null;
        foreach ($fields as $field) {
            if ($field->name === 'type') {
                $typeField = $field;
                break;
            }
        }

        self::assertNotNull($typeField);
        self::assertSame(FieldType::Enum, $typeField->type);
        self::assertSame(
            array_map(static fn(ThreadType $t): string => $t->value, ThreadType::cases()),
            $typeField->enumValues,
        );
    }

    #[Test]
    public function fieldsContainStatusEnumWithCorrectValues(): void
    {
        $fields = $this->resource->fields();
        $statusField = null;
        foreach ($fields as $field) {
            if ($field->name === 'status') {
                $statusField = $field;
                break;
            }
        }

        self::assertNotNull($statusField);
        self::assertSame(
            array_map(static fn(ThreadStatus $s): string => $s->value, ThreadStatus::cases()),
            $statusField->enumValues,
        );
    }

    #[Test]
    public function operationsDoNotIncludeCreate(): void
    {
        $ops = $this->resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertContains(ResourceOperation::Delete, $ops);
        self::assertNotContains(ResourceOperation::Create, $ops);
    }

    #[Test]
    public function bulkActionsIncludeFiveActions(): void
    {
        $actions = $this->resource->bulkActions();
        $names = array_map(static fn($a) => $a->name, $actions);

        self::assertCount(5, $actions);
        self::assertContains('lock', $names);
        self::assertContains('unlock', $names);
        self::assertContains('pin', $names);
        self::assertContains('unpin', $names);
        self::assertContains('delete', $names);
    }

    #[Test]
    public function deleteBulkActionIsDestructive(): void
    {
        $actions = $this->resource->bulkActions();
        $deleteAction = null;
        foreach ($actions as $action) {
            if ($action->name === 'delete') {
                $deleteAction = $action;
                break;
            }
        }

        self::assertNotNull($deleteAction);
        self::assertTrue($deleteAction->destructive);
    }

    #[Test]
    public function auditReadsIsTrue(): void
    {
        self::assertTrue($this->resource->auditReads());
    }

    #[Test]
    public function defaultSortIsCreatedAtDescending(): void
    {
        self::assertSame('created_at', $this->resource->defaultSortField());
        self::assertSame('desc', $this->resource->defaultSortDirection());
    }

    #[Test]
    public function titleFieldIsSortableAndSearchable(): void
    {
        $fields = $this->resource->fields();
        $titleField = null;
        foreach ($fields as $field) {
            if ($field->name === 'title') {
                $titleField = $field;
                break;
            }
        }

        self::assertNotNull($titleField);
        self::assertTrue($titleField->sortable);
        self::assertTrue($titleField->searchable);
    }
}
