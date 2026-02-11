<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Workflow;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowService;
use Pulsar\Extension\Cms\Workflow\ReviewStatus;

#[CoversClass(EditorialWorkflowService::class)]
final class EditorialWorkflowServiceTest extends TestCase
{
    private ConnectionInterface&Stub $db;
    private ContentRepositoryInterface&Stub $contentRepo;
    private AuditLoggerInterface&Stub $auditLogger;
    private EditorialWorkflowService $service;

    protected function setUp(): void
    {
        $this->db = $this->createStub(ConnectionInterface::class);
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        $this->service = new EditorialWorkflowService(
            $this->db,
            $this->contentRepo,
            $this->auditLogger,
        );
    }

    // -- submitForReview ------------------------------------------------------

    #[Test]
    public function submitForReviewCreatesReview(): void
    {
        $content = Content::create(id: 'c-01', contentType: ContentType::Article, authorId: 'u-01');
        $this->contentRepo->method('findById')->willReturn($content);

        $review = $this->service->submitForReview('c-01', 'u-01', 'en', 'reviewer-01');

        self::assertSame('c-01', $review->contentId);
        self::assertSame('u-01', $review->requestedBy);
        self::assertSame('reviewer-01', $review->reviewerId);
        self::assertSame('en', $review->locale);
        self::assertSame(ReviewStatus::Pending, $review->status);
        self::assertNull($review->decidedAt);
    }

    #[Test]
    public function submitForReviewWithoutReviewer(): void
    {
        $content = Content::create(id: 'c-02', contentType: ContentType::Page, authorId: 'u-02');
        $this->contentRepo->method('findById')->willReturn($content);

        $review = $this->service->submitForReview('c-02', 'u-02');

        self::assertNull($review->reviewerId);
        self::assertNull($review->locale);
    }

    #[Test]
    public function submitForReviewThrowsWhenContentNotFound(): void
    {
        $this->contentRepo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);

        $this->service->submitForReview('nonexistent', 'u-01');
    }

    #[Test]
    public function submitForReviewThrowsWhenInvalidTransition(): void
    {
        $content = Content::create(id: 'c-03', contentType: ContentType::Article, authorId: 'u-01');
        // Make it published first — Published cannot transition to InReview
        $published = $content->publish();
        $this->contentRepo->method('findById')->willReturn($published);

        $this->expectException(CmsException::class);

        $this->service->submitForReview('c-03', 'u-01');
    }

    // -- approve --------------------------------------------------------------

    #[Test]
    public function approveTransitionsReviewToApproved(): void
    {
        $now = new DateTimeImmutable('2025-01-15T10:00:00+00:00');
        $reviewRow = new Row([
            'id' => 'rev-01',
            'content_id' => 'c-01',
            'locale' => 'en',
            'requested_by' => 'u-01',
            'reviewer_id' => 'reviewer-01',
            'status' => 'pending',
            'comment' => null,
            'decision_reason' => null,
            'created_at' => $now->format('c'),
            'decided_at' => null,
        ]);

        $this->db->method('query')->willReturn(new Result([$reviewRow]));

        // Content must be InReview for approve transition
        $draft = Content::create(id: 'c-01', contentType: ContentType::Article, authorId: 'u-01');
        $inReview = $draft->submitForReview();
        $this->contentRepo->method('findById')->willReturn($inReview);

        $approved = $this->service->approve('rev-01', 'Looks great');

        self::assertSame(ReviewStatus::Approved, $approved->status);
        self::assertSame('Looks great', $approved->decisionReason);
        self::assertNotNull($approved->decidedAt);
    }

    #[Test]
    public function approveThrowsWhenReviewNotFound(): void
    {
        $this->db->method('query')->willReturn(new Result([]));

        $this->expectException(CmsException::class);

        $this->service->approve('nonexistent', 'reason');
    }

    // -- reject ---------------------------------------------------------------

    #[Test]
    public function rejectTransitionsReviewToRejected(): void
    {
        $now = new DateTimeImmutable('2025-01-15T10:00:00+00:00');
        $reviewRow = new Row([
            'id' => 'rev-02',
            'content_id' => 'c-02',
            'locale' => null,
            'requested_by' => 'u-02',
            'reviewer_id' => 'reviewer-01',
            'status' => 'pending',
            'comment' => null,
            'decision_reason' => null,
            'created_at' => $now->format('c'),
            'decided_at' => null,
        ]);

        $this->db->method('query')->willReturn(new Result([$reviewRow]));

        // Content must be InReview for reject transition
        $draft = Content::create(id: 'c-02', contentType: ContentType::Article, authorId: 'u-02');
        $inReview = $draft->submitForReview();
        $this->contentRepo->method('findById')->willReturn($inReview);

        $rejected = $this->service->reject('rev-02', 'Needs more detail', 'Please add examples');

        self::assertSame(ReviewStatus::Rejected, $rejected->status);
        self::assertSame('Needs more detail', $rejected->decisionReason);
        self::assertSame('Please add examples', $rejected->comment);
    }

    // -- cancelReview ---------------------------------------------------------

    #[Test]
    public function cancelReviewTransitionsToCancelled(): void
    {
        $now = new DateTimeImmutable('2025-01-15T10:00:00+00:00');
        $reviewRow = new Row([
            'id' => 'rev-03',
            'content_id' => 'c-03',
            'locale' => null,
            'requested_by' => 'u-03',
            'reviewer_id' => null,
            'status' => 'pending',
            'comment' => null,
            'decision_reason' => null,
            'created_at' => $now->format('c'),
            'decided_at' => null,
        ]);

        $this->db->method('query')->willReturn(new Result([$reviewRow]));

        $draft = Content::create(id: 'c-03', contentType: ContentType::Article, authorId: 'u-03');
        $inReview = $draft->submitForReview();
        $this->contentRepo->method('findById')->willReturn($inReview);

        $cancelled = $this->service->cancelReview('rev-03');

        self::assertSame(ReviewStatus::Cancelled, $cancelled->status);
        self::assertNotNull($cancelled->decidedAt);
    }

    // -- getPendingReviews ----------------------------------------------------

    #[Test]
    public function getPendingReviewsReturnsReviewList(): void
    {
        $now = new DateTimeImmutable('2025-01-15T10:00:00+00:00');
        $rows = [
            new Row([
                'id' => 'rev-a',
                'content_id' => 'c-a',
                'locale' => 'en',
                'requested_by' => 'u-a',
                'reviewer_id' => null,
                'status' => 'pending',
                'comment' => null,
                'decision_reason' => null,
                'created_at' => $now->format('c'),
                'decided_at' => null,
            ]),
            new Row([
                'id' => 'rev-b',
                'content_id' => 'c-b',
                'locale' => 'fr',
                'requested_by' => 'u-b',
                'reviewer_id' => 'reviewer-01',
                'status' => 'pending',
                'comment' => null,
                'decision_reason' => null,
                'created_at' => $now->format('c'),
                'decided_at' => null,
            ]),
        ];

        $this->db->method('query')->willReturn(new Result($rows));

        $reviews = $this->service->getPendingReviews();

        self::assertCount(2, $reviews);
        self::assertSame('rev-a', $reviews[0]->id);
        self::assertSame('rev-b', $reviews[1]->id);
    }

    #[Test]
    public function getPendingReviewsFilteredByReviewer(): void
    {
        $this->db->method('query')->willReturn(new Result([]));

        $reviews = $this->service->getPendingReviews('reviewer-01');

        self::assertSame([], $reviews);
    }
}
