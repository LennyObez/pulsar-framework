<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Forum\Admin\ForumPostResource;

#[CoversClass(ForumPostResource::class)]
final class ForumPostResourceTest extends TestCase
{
    private ForumPostResource $resource;

    protected function setUp(): void
    {
        $this->resource = new ForumPostResource();
    }

    #[Test]
    public function implementsDataResourceInterface(): void
    {
        self::assertInstanceOf(DataResourceInterface::class, $this->resource);
    }

    #[Test]
    public function nameReturnsCorrectIdentifier(): void
    {
        self::assertSame('forum_posts', $this->resource->name());
    }

    #[Test]
    public function labelsAreCorrect(): void
    {
        self::assertSame('Forum Post', $this->resource->label());
        self::assertSame('Forum Posts', $this->resource->pluralLabel());
    }

    #[Test]
    public function iconReturnsMessageCircle(): void
    {
        self::assertSame('message-circle', $this->resource->icon());
    }

    #[Test]
    public function fieldsContainThreadIdAsRelation(): void
    {
        $fields = $this->resource->fields();
        $threadField = null;
        foreach ($fields as $field) {
            if ($field->name === 'thread_id') {
                $threadField = $field;
                break;
            }
        }

        self::assertNotNull($threadField);
        self::assertSame(FieldType::Relation, $threadField->type);
        self::assertSame('forum_threads', $threadField->relationResource);
        self::assertFalse($threadField->editable);
    }

    #[Test]
    public function operationsAreReadOnlyPlusDelete(): void
    {
        $ops = $this->resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Delete, $ops);
        self::assertNotContains(ResourceOperation::Create, $ops);
        self::assertNotContains(ResourceOperation::Update, $ops);
    }

    #[Test]
    public function bulkActionDeleteIsDestructive(): void
    {
        $actions = $this->resource->bulkActions();

        self::assertCount(1, $actions);
        self::assertSame('delete', $actions[0]->name);
        self::assertTrue($actions[0]->destructive);
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
    public function voteScoreFieldIsSortable(): void
    {
        $fields = $this->resource->fields();
        $scoreField = null;
        foreach ($fields as $field) {
            if ($field->name === 'vote_score') {
                $scoreField = $field;
                break;
            }
        }

        self::assertNotNull($scoreField);
        self::assertTrue($scoreField->sortable);
        self::assertFalse($scoreField->editable);
    }
}
