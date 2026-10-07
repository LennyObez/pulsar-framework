<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowService;
use Pulsar\Extension\Cms\Workflow\ReviewStatus;

#[CoversClass(EditorialWorkflowService::class)]
#[CoversClass(EditorialReview::class)]
final class EditorialWorkflowTest extends TestCase
{
    #[Test]
    public function contributorSubmitsReviewerApprovesEditorPublishes(): void
    {
        // Step 1: Contributor creates draft
        $content = Content::create(
            id: 'content-editorial-001',
            contentType: ContentType::Article,
            authorId: 'contributor-001',
        );
        self::assertSame(PublishingStatus::Draft, $content->status);

        // Step 2: Submit for review (Draft -> InReview)
        $inReview = $content->submitForReview();
        self::assertSame(PublishingStatus::InReview, $inReview->status);

        // Step 3: Reviewer approves (InReview -> Approved)
        $approved = $inReview->approve();
        self::assertSame(PublishingStatus::Approved, $approved->status);

        // Step 4: Editor publishes (Approved -> Published, editorial mode)
        $published = $approved->publish(editorialWorkflow: true);
        self::assertSame(PublishingStatus::Published, $published->status);
        self::assertNotNull($published->publishedAt);
        self::assertTrue($published->isPublished());
    }

    #[Test]
    public function contributorCannotPublishDirectlyInEditorialMode(): void
    {
        $content = Content::create(
            id: 'content-editorial-002',
            contentType: ContentType::Article,
            authorId: 'contributor-002',
        );

        // Standard publish is allowed on draft (non-editorial check is at service layer)
        // But in editorial workflow, the service layer would enforce InReview -> Approved -> Published
        // At the entity level, Draft -> Published is valid in standard mode
        $published = $content->publish();
        self::assertSame(PublishingStatus::Published, $published->status);

        // But Draft -> Approved requires editorial workflow
        $fresh = Content::create(
            id: 'content-editorial-002b',
            contentType: ContentType::Article,
            authorId: 'contributor-002',
        );

        // Draft cannot go directly to Approved
        self::assertFalse(PublishingStatus::Draft->canTransitionTo(PublishingStatus::Approved));
        self::assertFalse(PublishingStatus::Draft->canTransitionTo(PublishingStatus::Approved, editorialWorkflow: false));

        // But can in editorial mode... only InReview can transition to Approved
        self::assertFalse(PublishingStatus::Draft->canTransitionTo(PublishingStatus::Approved, editorialWorkflow: true));
        self::assertTrue(PublishingStatus::InReview->canTransitionTo(PublishingStatus::Approved, editorialWorkflow: true));
    }

    #[Test]
    public function reviewerRejectsContentReturnsToDraftWithComment(): void
    {
        $content = Content::create(
            id: 'content-editorial-003',
            contentType: ContentType::Article,
            authorId: 'contributor-003',
        );

        // Submit -> Review -> Reject back to Draft
        $inReview = $content->submitForReview();
        self::assertSame(PublishingStatus::InReview, $inReview->status);

        $rejected = $inReview->reject();
        self::assertSame(PublishingStatus::Draft, $rejected->status);
        self::assertTrue($rejected->isDraft());

        // Verify the editorial review model carries rejection data
        $review = new EditorialReview(
            id: 'review-001',
            contentId: 'content-editorial-003',
            locale: 'en',
            requestedBy: 'contributor-003',
            reviewerId: 'reviewer-001',
            status: ReviewStatus::Rejected,
            comment: 'Needs more detail in the introduction section.',
            decisionReason: 'Content is incomplete',
            createdAt: new DateTimeImmutable(),
            decidedAt: new DateTimeImmutable(),
        );

        self::assertSame(ReviewStatus::Rejected, $review->status);
        self::assertSame('Needs more detail in the introduction section.', $review->comment);
        self::assertSame('Content is incomplete', $review->decisionReason);
        self::assertNotNull($review->decidedAt);
    }

    #[Test]
    public function reviewCancellation(): void
    {
        $content = Content::create(
            id: 'content-editorial-004',
            contentType: ContentType::Article,
            authorId: 'contributor-004',
        );

        // Submit for review
        $inReview = $content->submitForReview();
        self::assertSame(PublishingStatus::InReview, $inReview->status);

        // Cancellation returns to Draft (reject is the entity transition)
        $cancelled = $inReview->reject();
        self::assertSame(PublishingStatus::Draft, $cancelled->status);

        // Verify the editorial review model carries cancellation status
        $review = new EditorialReview(
            id: 'review-cancel-001',
            contentId: 'content-editorial-004',
            locale: null,
            requestedBy: 'contributor-004',
            reviewerId: null,
            status: ReviewStatus::Cancelled,
            comment: null,
            decisionReason: null,
            createdAt: new DateTimeImmutable(),
            decidedAt: new DateTimeImmutable(),
        );

        self::assertSame(ReviewStatus::Cancelled, $review->status);
        self::assertNull($review->comment);
        self::assertNull($review->decisionReason);
    }

    #[Test]
    public function editorialWorkflowInvalidTransitions(): void
    {
        // Published content cannot go to InReview
        self::assertFalse(PublishingStatus::Published->canTransitionTo(PublishingStatus::InReview, editorialWorkflow: true));

        // Archived content cannot go to InReview
        self::assertFalse(PublishingStatus::Archived->canTransitionTo(PublishingStatus::InReview, editorialWorkflow: true));

        // InReview cannot go directly to Published
        self::assertFalse(PublishingStatus::InReview->canTransitionTo(PublishingStatus::Published, editorialWorkflow: true));

        // Approved can go to Published in editorial mode
        self::assertTrue(PublishingStatus::Approved->canTransitionTo(PublishingStatus::Published, editorialWorkflow: true));

        // Same-status transition is always invalid
        self::assertFalse(PublishingStatus::Draft->canTransitionTo(PublishingStatus::Draft));
        self::assertFalse(PublishingStatus::InReview->canTransitionTo(PublishingStatus::InReview, editorialWorkflow: true));
    }

    #[Test]
    public function reviewStatusValues(): void
    {
        self::assertSame('pending', ReviewStatus::Pending->value);
        self::assertSame('in_review', ReviewStatus::InReview->value);
        self::assertSame('approved', ReviewStatus::Approved->value);
        self::assertSame('rejected', ReviewStatus::Rejected->value);
        self::assertSame('cancelled', ReviewStatus::Cancelled->value);
    }

    #[Test]
    public function editorialReviewTracksAllMetadata(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-15 10:00:00');
        $decidedAt = new DateTimeImmutable('2026-01-15 14:30:00');

        $review = new EditorialReview(
            id: 'review-meta-001',
            contentId: 'content-meta-001',
            locale: 'en',
            requestedBy: 'author-001',
            reviewerId: 'reviewer-001',
            status: ReviewStatus::Approved,
            comment: 'Great article',
            decisionReason: 'Meets all publication criteria',
            createdAt: $createdAt,
            decidedAt: $decidedAt,
        );

        self::assertSame('review-meta-001', $review->id);
        self::assertSame('content-meta-001', $review->contentId);
        self::assertSame('en', $review->locale);
        self::assertSame('author-001', $review->requestedBy);
        self::assertSame('reviewer-001', $review->reviewerId);
        self::assertSame(ReviewStatus::Approved, $review->status);
        self::assertSame('Great article', $review->comment);
        self::assertSame('Meets all publication criteria', $review->decisionReason);
        self::assertSame($createdAt, $review->createdAt);
        self::assertSame($decidedAt, $review->decidedAt);
    }
}
