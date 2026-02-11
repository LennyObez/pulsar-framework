<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Workflow;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\ReviewStatus;

#[CoversClass(EditorialReview::class)]
#[CoversClass(ReviewStatus::class)]
final class EditorialReviewTest extends TestCase
{
    #[Test]
    public function pendingReviewConstructor(): void
    {
        $now = new DateTimeImmutable('2025-03-07T09:00:00+00:00');

        $review = new EditorialReview(
            id: '0194d4e0-1111-7000-2222-000000000001',
            contentId: '0194d4e0-1111-7000-2222-000000000002',
            locale: 'en',
            requestedBy: 'user-author',
            reviewerId: 'user-editor',
            status: ReviewStatus::Pending,
            comment: null,
            decisionReason: null,
            createdAt: $now,
            decidedAt: null,
        );

        self::assertSame(ReviewStatus::Pending, $review->status);
        self::assertSame('en', $review->locale);
        self::assertSame('user-author', $review->requestedBy);
        self::assertSame('user-editor', $review->reviewerId);
        self::assertNull($review->comment);
        self::assertNull($review->decisionReason);
        self::assertNull($review->decidedAt);
    }

    #[Test]
    public function approvedReviewWithDecision(): void
    {
        $now = new DateTimeImmutable();

        $review = new EditorialReview(
            id: 'rev-01',
            contentId: 'cnt-01',
            locale: null,
            requestedBy: 'user-01',
            reviewerId: null,
            status: ReviewStatus::Approved,
            comment: 'Well written article',
            decisionReason: 'Content meets editorial standards',
            createdAt: new DateTimeImmutable('-1 hour'),
            decidedAt: $now,
        );

        self::assertSame(ReviewStatus::Approved, $review->status);
        self::assertNull($review->locale);
        self::assertNull($review->reviewerId);
        self::assertSame('Well written article', $review->comment);
        self::assertSame('Content meets editorial standards', $review->decisionReason);
        self::assertNotNull($review->decidedAt);
    }

    #[Test]
    public function rejectedReview(): void
    {
        $review = new EditorialReview(
            id: 'rev-02',
            contentId: 'cnt-02',
            locale: 'fr',
            requestedBy: 'user-02',
            reviewerId: 'user-editor',
            status: ReviewStatus::Rejected,
            comment: 'Needs significant revision',
            decisionReason: 'Factual inaccuracies found',
            createdAt: new DateTimeImmutable('-2 hours'),
            decidedAt: new DateTimeImmutable(),
        );

        self::assertSame(ReviewStatus::Rejected, $review->status);
        self::assertSame('Factual inaccuracies found', $review->decisionReason);
    }

    #[Test]
    public function cancelledReview(): void
    {
        $review = new EditorialReview(
            id: 'rev-03',
            contentId: 'cnt-03',
            locale: null,
            requestedBy: 'user-03',
            reviewerId: null,
            status: ReviewStatus::Cancelled,
            comment: null,
            decisionReason: null,
            createdAt: new DateTimeImmutable('-3 hours'),
            decidedAt: new DateTimeImmutable(),
        );

        self::assertSame(ReviewStatus::Cancelled, $review->status);
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
}
