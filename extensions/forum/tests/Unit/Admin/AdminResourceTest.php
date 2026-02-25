<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Forum\Admin\ForumCategoryResource;
use Pulsar\Extension\Forum\Admin\ForumPostResource;
use Pulsar\Extension\Forum\Admin\ForumProfileResource;
use Pulsar\Extension\Forum\Admin\ForumReportResource;
use Pulsar\Extension\Forum\Admin\ForumTagResource;
use Pulsar\Extension\Forum\Admin\ForumThreadResource;

final class AdminResourceTest extends TestCase
{
    #[Test]
    public function threadResourceMetadata(): void
    {
        $resource = new ForumThreadResource();

        self::assertInstanceOf(DataResourceInterface::class, $resource);
        self::assertSame('forum_threads', $resource->name());
        self::assertSame('Forum Thread', $resource->label());
        self::assertSame('Forum Threads', $resource->pluralLabel());
        self::assertSame('message-square', $resource->icon());
        self::assertSame('id', $resource->primaryKey());
        self::assertSame('created_at', $resource->defaultSortField());
        self::assertSame('desc', $resource->defaultSortDirection());
        self::assertTrue($resource->auditReads());
    }

    #[Test]
    public function threadResourceOperations(): void
    {
        $resource = new ForumThreadResource();
        $ops = $resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertContains(ResourceOperation::Delete, $ops);
        self::assertNotContains(ResourceOperation::Create, $ops);
    }

    #[Test]
    public function threadResourceHasBulkActions(): void
    {
        $resource = new ForumThreadResource();
        $actions = $resource->bulkActions();

        self::assertCount(5, $actions);
    }

    #[Test]
    public function threadResourceFieldsAreNotEmpty(): void
    {
        $resource = new ForumThreadResource();

        self::assertNotEmpty($resource->fields());
        self::assertNotEmpty($resource->exportableFields());
    }

    #[Test]
    public function postResourceMetadata(): void
    {
        $resource = new ForumPostResource();

        self::assertSame('forum_posts', $resource->name());
        self::assertSame('Forum Post', $resource->label());
        self::assertSame('Forum Posts', $resource->pluralLabel());
        self::assertSame('message-circle', $resource->icon());
        self::assertTrue($resource->auditReads());
    }

    #[Test]
    public function postResourceOperations(): void
    {
        $resource = new ForumPostResource();
        $ops = $resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Delete, $ops);
        self::assertNotContains(ResourceOperation::Create, $ops);
        self::assertNotContains(ResourceOperation::Update, $ops);
    }

    #[Test]
    public function categoryResourceMetadata(): void
    {
        $resource = new ForumCategoryResource();

        self::assertSame('forum_categories', $resource->name());
        self::assertSame('Forum Category', $resource->label());
        self::assertSame('Forum Categories', $resource->pluralLabel());
        self::assertSame('folder', $resource->icon());
        self::assertSame('sort_order', $resource->defaultSortField());
        self::assertSame('asc', $resource->defaultSortDirection());
        self::assertFalse($resource->auditReads());
    }

    #[Test]
    public function categoryResourceSupportsFullCrud(): void
    {
        $resource = new ForumCategoryResource();
        $ops = $resource->operations();

        self::assertContains(ResourceOperation::Create, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertContains(ResourceOperation::Delete, $ops);
    }

    #[Test]
    public function tagResourceMetadata(): void
    {
        $resource = new ForumTagResource();

        self::assertSame('forum_tags', $resource->name());
        self::assertSame('Forum Tag', $resource->label());
        self::assertSame('tag', $resource->icon());
        self::assertSame('usage_count', $resource->defaultSortField());
        self::assertSame('desc', $resource->defaultSortDirection());
        self::assertEmpty($resource->bulkActions());
    }

    #[Test]
    public function profileResourceMetadata(): void
    {
        $resource = new ForumProfileResource();

        self::assertSame('forum_profiles', $resource->name());
        self::assertSame('Forum Profile', $resource->label());
        self::assertSame('user', $resource->icon());
        self::assertSame('reputation_score', $resource->defaultSortField());
        self::assertTrue($resource->auditReads());
    }

    #[Test]
    public function profileResourceDoesNotSupportCreateOrDelete(): void
    {
        $resource = new ForumProfileResource();
        $ops = $resource->operations();

        self::assertContains(ResourceOperation::List, $ops);
        self::assertContains(ResourceOperation::View, $ops);
        self::assertContains(ResourceOperation::Update, $ops);
        self::assertNotContains(ResourceOperation::Create, $ops);
        self::assertNotContains(ResourceOperation::Delete, $ops);
    }

    #[Test]
    public function reportResourceMetadata(): void
    {
        $resource = new ForumReportResource();

        self::assertSame('forum_reports', $resource->name());
        self::assertSame('Forum Report', $resource->label());
        self::assertSame('flag', $resource->icon());
        self::assertSame('created_at', $resource->defaultSortField());
        self::assertTrue($resource->auditReads());
    }

    #[Test]
    public function reportResourceHasBulkActions(): void
    {
        $resource = new ForumReportResource();
        $actions = $resource->bulkActions();

        self::assertCount(2, $actions);
    }
}
