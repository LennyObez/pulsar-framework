<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Feedback;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackStatus;

use function strlen;

final class FeedbackEntityTest extends TestCase
{
    #[Test]
    public function createSetsDefaultsAndGeneratesId(): void
    {
        $feedback = Feedback::create(
            userId: 'user-1',
            category: FeedbackCategory::Bug,
            description: 'The button does not work on mobile',
        );

        self::assertSame(32, strlen($feedback->id));
        self::assertSame('user-1', $feedback->userId);
        self::assertSame(FeedbackCategory::Bug, $feedback->category);
        self::assertSame('The button does not work on mobile', $feedback->description);
        self::assertSame([], $feedback->context);
        self::assertSame(FeedbackStatus::Received, $feedback->status);
        self::assertNull($feedback->adminResponse);
        self::assertNull($feedback->githubIssueUrl);
        self::assertInstanceOf(DateTimeImmutable::class, $feedback->createdAt);
        self::assertInstanceOf(DateTimeImmutable::class, $feedback->updatedAt);
    }

    #[Test]
    public function createAcceptsContext(): void
    {
        $context = ['page' => '/settings', 'browser' => 'Firefox'];

        $feedback = Feedback::create(
            userId: 'user-2',
            category: FeedbackCategory::Feature,
            description: 'Please add dark mode support',
            context: $context,
        );

        self::assertSame($context, $feedback->context);
    }

    #[Test]
    public function updateStatusTransitionsCorrectly(): void
    {
        $feedback = Feedback::create(
            userId: 'user-1',
            category: FeedbackCategory::Bug,
            description: 'Crash on startup',
        );

        self::assertSame(FeedbackStatus::Received, $feedback->status);

        $investigating = $feedback->updateStatus(FeedbackStatus::Investigating);

        self::assertSame(FeedbackStatus::Investigating, $investigating->status);
        self::assertSame($feedback->id, $investigating->id);
        self::assertGreaterThanOrEqual($feedback->updatedAt, $investigating->updatedAt);
    }

    #[Test]
    public function updateStatusPreservesOtherFields(): void
    {
        $feedback = Feedback::create(
            userId: 'user-1',
            category: FeedbackCategory::Question,
            description: 'How do I reset my password?',
            context: ['page' => '/help'],
        );

        $resolved = $feedback->updateStatus(FeedbackStatus::Resolved);

        self::assertSame('user-1', $resolved->userId);
        self::assertSame(FeedbackCategory::Question, $resolved->category);
        self::assertSame('How do I reset my password?', $resolved->description);
        self::assertSame(['page' => '/help'], $resolved->context);
    }

    #[Test]
    public function addAdminResponseSetsResponse(): void
    {
        $feedback = Feedback::create(
            userId: 'user-1',
            category: FeedbackCategory::Bug,
            description: 'Crash when scrolling fast',
        );

        self::assertNull($feedback->adminResponse);

        $withResponse = $feedback->addAdminResponse('Fixed in v2.1.0, thank you!');

        self::assertSame('Fixed in v2.1.0, thank you!', $withResponse->adminResponse);
        self::assertSame($feedback->id, $withResponse->id);
    }

    #[Test]
    public function addAdminResponseOverwritesPrevious(): void
    {
        $feedback = Feedback::create(
            userId: 'user-1',
            category: FeedbackCategory::Bug,
            description: 'Loading takes too long',
        );

        $first = $feedback->addAdminResponse('Looking into it');
        $second = $first->addAdminResponse('Resolved in latest update');

        self::assertSame('Resolved in latest update', $second->adminResponse);
    }

    #[Test]
    public function linkGitHubIssueSetsUrl(): void
    {
        $feedback = Feedback::create(
            userId: 'user-1',
            category: FeedbackCategory::Feature,
            description: 'Add export to CSV feature',
        );

        self::assertNull($feedback->githubIssueUrl);

        $linked = $feedback->linkGitHubIssue('https://github.com/org/repo/issues/42');

        self::assertSame('https://github.com/org/repo/issues/42', $linked->githubIssueUrl);
        self::assertSame($feedback->id, $linked->id);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $now = new DateTimeImmutable();

        $feedback = new Feedback(
            id: 'fb-custom',
            userId: 'user-5',
            category: FeedbackCategory::Improvement,
            description: 'Improve search performance',
            context: ['query' => 'slow search'],
            status: FeedbackStatus::WontFix,
            adminResponse: 'Not planned',
            githubIssueUrl: 'https://github.com/issues/99',
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertSame('fb-custom', $feedback->id);
        self::assertSame('user-5', $feedback->userId);
        self::assertSame(FeedbackCategory::Improvement, $feedback->category);
        self::assertSame(FeedbackStatus::WontFix, $feedback->status);
        self::assertSame('Not planned', $feedback->adminResponse);
        self::assertSame('https://github.com/issues/99', $feedback->githubIssueUrl);
    }

    #[Test]
    public function chainingMultipleTransitions(): void
    {
        $feedback = Feedback::create(
            userId: 'user-1',
            category: FeedbackCategory::Bug,
            description: 'App crashes on photo upload',
        );

        $updated = $feedback
            ->updateStatus(FeedbackStatus::Investigating)
            ->addAdminResponse('Investigating the crash logs')
            ->linkGitHubIssue('https://github.com/org/repo/issues/10')
            ->updateStatus(FeedbackStatus::Resolved);

        self::assertSame(FeedbackStatus::Resolved, $updated->status);
        self::assertSame('Investigating the crash logs', $updated->adminResponse);
        self::assertSame('https://github.com/org/repo/issues/10', $updated->githubIssueUrl);
    }
}
