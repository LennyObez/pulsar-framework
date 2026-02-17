<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackStatus;

use function strlen;

final class FeedbackTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');

        $feedback = new Feedback(
            id: 'abc123',
            userId: 'user-1',
            category: FeedbackCategory::Bug,
            description: 'Something is broken',
            context: ['page' => '/dashboard'],
            status: FeedbackStatus::Received,
            adminResponse: null,
            githubIssueUrl: null,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertSame('abc123', $feedback->id);
        self::assertSame('user-1', $feedback->userId);
        self::assertSame(FeedbackCategory::Bug, $feedback->category);
        self::assertSame('Something is broken', $feedback->description);
        self::assertSame(['page' => '/dashboard'], $feedback->context);
        self::assertSame(FeedbackStatus::Received, $feedback->status);
        self::assertNull($feedback->adminResponse);
        self::assertNull($feedback->githubIssueUrl);
        self::assertSame($now, $feedback->createdAt);
        self::assertSame($now, $feedback->updatedAt);
    }

    #[Test]
    public function createGeneratesIdAndSetsDefaults(): void
    {
        $feedback = Feedback::create(
            userId: 'user-42',
            category: FeedbackCategory::Feature,
            description: 'Please add dark mode',
            context: ['browser' => 'Firefox'],
        );

        self::assertSame(32, strlen($feedback->id));
        self::assertSame('user-42', $feedback->userId);
        self::assertSame(FeedbackCategory::Feature, $feedback->category);
        self::assertSame('Please add dark mode', $feedback->description);
        self::assertSame(['browser' => 'Firefox'], $feedback->context);
        self::assertSame(FeedbackStatus::Received, $feedback->status);
        self::assertNull($feedback->adminResponse);
        self::assertNull($feedback->githubIssueUrl);
        self::assertInstanceOf(DateTimeImmutable::class, $feedback->createdAt);
        self::assertInstanceOf(DateTimeImmutable::class, $feedback->updatedAt);
    }

    #[Test]
    public function createWithEmptyContext(): void
    {
        $feedback = Feedback::create(
            userId: 'user-1',
            category: FeedbackCategory::Question,
            description: 'How does this work?',
        );

        self::assertSame([], $feedback->context);
    }

    #[Test]
    public function createGeneratesUniqueIds(): void
    {
        $f1 = Feedback::create('u1', FeedbackCategory::Bug, 'First feedback');
        $f2 = Feedback::create('u2', FeedbackCategory::Bug, 'Second feedback');

        self::assertNotSame($f1->id, $f2->id);
    }

    #[Test]
    public function updateStatusReturnsNewInstanceWithUpdatedStatus(): void
    {
        $feedback = Feedback::create('u1', FeedbackCategory::Bug, 'Test description');
        $updated = $feedback->updateStatus(FeedbackStatus::Investigating);

        self::assertSame(FeedbackStatus::Investigating, $updated->status);
        self::assertSame(FeedbackStatus::Received, $feedback->status);
        self::assertSame($feedback->id, $updated->id);
        self::assertSame($feedback->userId, $updated->userId);
        self::assertSame($feedback->description, $updated->description);
    }

    #[Test]
    public function addAdminResponseReturnsNewInstanceWithResponse(): void
    {
        $feedback = Feedback::create('u1', FeedbackCategory::Improvement, 'Add autocomplete');
        $updated = $feedback->addAdminResponse('Thanks, we will look into it.');

        self::assertSame('Thanks, we will look into it.', $updated->adminResponse);
        self::assertNull($feedback->adminResponse);
        self::assertSame($feedback->id, $updated->id);
    }

    #[Test]
    public function linkGitHubIssueReturnsNewInstanceWithUrl(): void
    {
        $feedback = Feedback::create('u1', FeedbackCategory::Bug, 'Crash on login');
        $updated = $feedback->linkGitHubIssue('https://github.com/org/repo/issues/42');

        self::assertSame('https://github.com/org/repo/issues/42', $updated->githubIssueUrl);
        self::assertNull($feedback->githubIssueUrl);
        self::assertSame($feedback->id, $updated->id);
    }

    #[Test]
    public function updateStatusUpdatesTimestamp(): void
    {
        $feedback = Feedback::create('u1', FeedbackCategory::Bug, 'Test description');
        $original = $feedback->updatedAt;

        // Small delay to ensure timestamp differs
        $updated = $feedback->updateStatus(FeedbackStatus::Resolved);

        self::assertGreaterThanOrEqual($original, $updated->updatedAt);
    }
}
