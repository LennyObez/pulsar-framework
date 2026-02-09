<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Dashboard;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\ModerationStatus;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Dashboard\ModerationQueueWidget;

#[CoversClass(ModerationQueueWidget::class)]
final class ModerationQueueWidgetTest extends TestCase
{
    #[Test]
    public function test_get_name_returns_moderation_queue(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $widget = new ModerationQueueWidget($repo);

        self::assertSame('moderation_queue', $widget->getName());
    }

    #[Test]
    public function test_get_template_returns_expected_path(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $widget = new ModerationQueueWidget($repo);

        self::assertSame('dashboard/widgets/moderation-queue', $widget->getTemplate());
    }

    #[Test]
    public function test_get_data_with_no_pending_comments(): void
    {
        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findPendingModeration')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100),
        );

        $widget = new ModerationQueueWidget($repo);
        $data = $widget->getData();

        self::assertSame(0, $data['pending_count']);
        self::assertSame(0, $data['urgent_count']);
        self::assertSame('/admin/cms/comments?status=pending', $data['moderation_url']);
        self::assertSame(24, $data['urgent_threshold_hours']);
    }

    #[Test]
    public function test_get_data_counts_urgent_comments_older_than_24h(): void
    {
        $recentComment = $this->createComment(new DateTimeImmutable('-1 hour'));
        $urgentComment = $this->createComment(new DateTimeImmutable('-48 hours'));
        $anotherUrgent = $this->createComment(new DateTimeImmutable('-25 hours'));

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findPendingModeration')->willReturn(
            new PaginationResult(
                items: [$recentComment, $urgentComment, $anotherUrgent],
                total: 3,
                hasMore: false,
                perPage: 100,
            ),
        );

        $widget = new ModerationQueueWidget($repo);
        $data = $widget->getData();

        self::assertSame(3, $data['pending_count']);
        self::assertSame(2, $data['urgent_count']);
    }

    #[Test]
    public function test_get_data_uses_total_from_pagination(): void
    {
        $comment = $this->createComment(new DateTimeImmutable());

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findPendingModeration')->willReturn(
            new PaginationResult(
                items: [$comment],
                total: 150,
                hasMore: true,
                perPage: 100,
            ),
        );

        $widget = new ModerationQueueWidget($repo);
        $data = $widget->getData();

        self::assertSame(150, $data['pending_count']);
    }

    #[Test]
    public function test_get_data_falls_back_to_item_count_when_total_null(): void
    {
        $comment = $this->createComment(new DateTimeImmutable());

        $repo = $this->createStub(CommentRepositoryInterface::class);
        $repo->method('findPendingModeration')->willReturn(
            new PaginationResult(
                items: [$comment],
                total: null,
                hasMore: false,
                perPage: 100,
            ),
        );

        $widget = new ModerationQueueWidget($repo);
        $data = $widget->getData();

        self::assertSame(1, $data['pending_count']);
    }

    #[Test]
    public function test_get_data_passes_tenant_id(): void
    {
        $tenantId = '01912345-6789-7abc-8def-000000000001';

        $repo = $this->createMock(CommentRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findPendingModeration')
            ->with($tenantId, 1, 100)
            ->willReturn(
                new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100),
            );

        $widget = new ModerationQueueWidget($repo, $tenantId);
        $widget->getData();
    }

    private function createComment(DateTimeImmutable $createdAt): Comment
    {
        return new Comment(
            id: '01912345-6789-7abc-8def-' . bin2hex(random_bytes(6)),
            tenantId: null,
            contentId: '01912345-6789-7abc-8def-0123456789ab',
            parentId: null,
            authorId: '01912345-6789-7abc-8def-0123456789cd',
            guestName: null,
            guestEmail: null,
            body: 'Test comment',
            status: ModerationStatus::Pending,
            ipHash: 'hash',
            userAgentHash: 'hash',
            editedAt: null,
            editWindowExpiresAt: null,
            dataClassification: DataClassification::Public,
            createdAt: $createdAt,
            deletedAt: null,
        );
    }
}
