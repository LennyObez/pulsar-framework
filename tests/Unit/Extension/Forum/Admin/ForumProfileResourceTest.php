<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Forum\Admin\ForumProfileResource;

#[CoversClass(ForumProfileResource::class)]
final class ForumProfileResourceTest extends TestCase
{
    private ForumProfileResource $resource;

    protected function setUp(): void
    {
        $this->resource = new ForumProfileResource();
    }

    #[Test]
    public function nameReturnsCorrectIdentifier(): void
    {
        self::assertSame('forum_profiles', $this->resource->name());
    }

    #[Test]
    public function labelsAreCorrect(): void
    {
        self::assertSame('Forum Profile', $this->resource->label());
        self::assertSame('Forum Profiles', $this->resource->pluralLabel());
    }

    #[Test]
    public function iconReturnsUser(): void
    {
        self::assertSame('user', $this->resource->icon());
    }

    #[Test]
    public function operationsAreListViewUpdate(): void
    {
        $ops = $this->resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertNotContains(ResourceOperation::Create, $ops);
        self::assertNotContains(ResourceOperation::Delete, $ops);
    }

    #[Test]
    public function bulkActionsIncludeBanAndUnban(): void
    {
        $actions = $this->resource->bulkActions();
        $names = array_map(static fn($a) => $a->name, $actions);

        self::assertContains('ban', $names);
        self::assertContains('unban', $names);
    }

    #[Test]
    public function isBannedFieldIsFilterable(): void
    {
        $fields = $this->resource->fields();
        foreach ($fields as $field) {
            if ($field->name === 'is_banned') {
                self::assertTrue($field->filterable);
                self::assertSame(FieldType::Boolean, $field->type);

                return;
            }
        }

        self::fail('Field is_banned not found');
    }

    #[Test]
    public function auditReadsIsTrue(): void
    {
        self::assertTrue($this->resource->auditReads());
    }

    #[Test]
    public function defaultSortIsReputationScoreDescending(): void
    {
        self::assertSame('reputation_score', $this->resource->defaultSortField());
        self::assertSame('desc', $this->resource->defaultSortDirection());
    }

    #[Test]
    public function primaryKeyIsId(): void
    {
        self::assertSame('id', $this->resource->primaryKey());
    }

    #[Test]
    public function reputationScoreFieldIsSortable(): void
    {
        $fields = $this->resource->fields();
        foreach ($fields as $field) {
            if ($field->name === 'reputation_score') {
                self::assertTrue($field->sortable);
                self::assertFalse($field->editable);

                return;
            }
        }

        self::fail('Field reputation_score not found');
    }
}
