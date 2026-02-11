<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Forum\Admin\ForumReportResource;
use Pulsar\Extension\Forum\Domain\ReportStatus;

use function array_map;

#[CoversClass(ForumReportResource::class)]
final class ForumReportResourceTest extends TestCase
{
    private ForumReportResource $resource;

    protected function setUp(): void
    {
        $this->resource = new ForumReportResource();
    }

    #[Test]
    public function nameReturnsCorrectIdentifier(): void
    {
        self::assertSame('forum_reports', $this->resource->name());
    }

    #[Test]
    public function labelsAreCorrect(): void
    {
        self::assertSame('Forum Report', $this->resource->label());
        self::assertSame('Forum Reports', $this->resource->pluralLabel());
    }

    #[Test]
    public function iconReturnsFlag(): void
    {
        self::assertSame('flag', $this->resource->icon());
    }

    #[Test]
    public function statusFieldEnumValuesMatchReportStatusCases(): void
    {
        $fields = $this->resource->fields();
        foreach ($fields as $field) {
            if ($field->name === 'status') {
                self::assertSame(FieldType::Enum, $field->type);
                self::assertSame(
                    array_map(static fn(ReportStatus $s): string => $s->value, ReportStatus::cases()),
                    $field->enumValues,
                );

                return;
            }
        }

        self::fail('Field status not found');
    }

    #[Test]
    public function targetTypeFieldHasCorrectEnumValues(): void
    {
        $fields = $this->resource->fields();
        foreach ($fields as $field) {
            if ($field->name === 'target_type') {
                self::assertSame(['thread', 'post'], $field->enumValues);
                self::assertTrue($field->filterable);

                return;
            }
        }

        self::fail('Field target_type not found');
    }

    #[Test]
    public function operationsAreListViewUpdate(): void
    {
        $ops = $this->resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertNotContains(ResourceOperation::Delete, $ops);
    }

    #[Test]
    public function bulkActionsIncludeDismissAndAction(): void
    {
        $actions = $this->resource->bulkActions();
        $names = array_map(static fn($a) => $a->name, $actions);

        self::assertContains('dismiss', $names);
        self::assertContains('action', $names);
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
}
