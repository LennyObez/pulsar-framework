<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Feedback;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\FeedbackStatus;
use Pulsar\Extension\Feedback\Internal\FeedbackService;

final class FeedbackServiceTest extends TestCase
{
    private FeedbackRepositoryInterface&Stub $repo;
    private FeedbackService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(FeedbackRepositoryInterface::class);
        $this->service = new FeedbackService($this->repo);
    }

    #[Test]
    public function submitCreatesNewFeedbackWithReceivedStatus(): void
    {
        $this->repo->method('countByUserToday')->willReturn(0);

        $feedback = $this->service->submit(
            userId: 'user-001',
            category: FeedbackCategory::Bug,
            description: 'The login page crashes on iOS Safari',
            context: ['page' => '/login', 'browser' => 'Safari/iOS'],
        );

        self::assertInstanceOf(Feedback::class, $feedback);
        self::assertSame('user-001', $feedback->userId);
        self::assertSame(FeedbackCategory::Bug, $feedback->category);
        self::assertSame('The login page crashes on iOS Safari', $feedback->description);
        self::assertSame(FeedbackStatus::Received, $feedback->status);
        self::assertSame(['page' => '/login', 'browser' => 'Safari/iOS'], $feedback->context);
        self::assertNull($feedback->adminResponse);
        self::assertNull($feedback->githubIssueUrl);
    }

    #[Test]
    public function submitThrowsWhenDescriptionTooShort(): void
    {
        $this->repo->method('countByUserToday')->willReturn(0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('between 10 and 5000');

        $this->service->submit('user-001', FeedbackCategory::Bug, 'Short', []);
    }

    #[Test]
    public function submitThrowsWhenDescriptionTooLong(): void
    {
        $this->repo->method('countByUserToday')->willReturn(0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('between 10 and 5000');

        $this->service->submit('user-001', FeedbackCategory::Bug, str_repeat('a', 5001), []);
    }

    #[Test]
    public function submitAcceptsMinimumDescriptionLength(): void
    {
        $this->repo->method('countByUserToday')->willReturn(0);

        $feedback = $this->service->submit(
            'user-001',
            FeedbackCategory::Feature,
            'Exactly 10', // 10 chars
            [],
        );

        self::assertSame('Exactly 10', $feedback->description);
    }

    #[Test]
    public function submitAcceptsMaximumDescriptionLength(): void
    {
        $this->repo->method('countByUserToday')->willReturn(0);

        $description = str_repeat('a', 5000);
        $feedback = $this->service->submit('user-001', FeedbackCategory::Improvement, $description, []);

        self::assertSame(5000, mb_strlen($feedback->description));
    }

    #[Test]
    public function submitThrowsWhenDailyRateLimitExceeded(): void
    {
        $this->repo->method('countByUserToday')->willReturn(10);

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessageIsOrContains('Rate limit exceeded');

        $this->service->submit(
            'user-001',
            FeedbackCategory::Question,
            'This is a valid question submission',
            [],
        );
    }

    #[Test]
    public function submitAllowsUpToMaximumSubmissionsPerDay(): void
    {
        $this->repo->method('countByUserToday')->willReturn(9);

        $feedback = $this->service->submit(
            'user-001',
            FeedbackCategory::Other,
            'This is the tenth submission today',
            [],
        );

        self::assertInstanceOf(Feedback::class, $feedback);
    }

    #[Test]
    public function updateStatusTransitionsCorrectly(): void
    {
        $existing = Feedback::create('user-001', FeedbackCategory::Bug, 'Crash on login page', []);
        $this->repo->method('findById')->willReturn($existing);

        $updated = $this->service->updateStatus($existing->id, FeedbackStatus::Investigating);

        self::assertNotNull($updated);
        self::assertSame(FeedbackStatus::Investigating, $updated->status);
    }

    #[Test]
    public function updateStatusReturnsNullForMissingFeedback(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $result = $this->service->updateStatus('missing-id', FeedbackStatus::Resolved);

        self::assertNull($result);
    }

    #[Test]
    public function addResponseAttachesAdminReply(): void
    {
        $existing = Feedback::create('user-001', FeedbackCategory::Bug, 'Crash on login page', []);
        $this->repo->method('findById')->willReturn($existing);

        $updated = $this->service->addResponse($existing->id, 'Fixed in v2.1.0');

        self::assertNotNull($updated);
        self::assertSame('Fixed in v2.1.0', $updated->adminResponse);
    }

    #[Test]
    public function addResponseReturnsNullForMissingFeedback(): void
    {
        $this->repo->method('findById')->willReturn(null);

        self::assertNull($this->service->addResponse('missing', 'response'));
    }

    #[Test]
    public function linkIssueAttachesGitHubUrl(): void
    {
        $existing = Feedback::create('user-001', FeedbackCategory::Feature, 'Add dark mode support for settings', []);
        $this->repo->method('findById')->willReturn($existing);

        $updated = $this->service->linkIssue($existing->id, 'https://github.com/org/repo/issues/42');

        self::assertNotNull($updated);
        self::assertSame('https://github.com/org/repo/issues/42', $updated->githubIssueUrl);
    }

    #[Test]
    public function linkIssueReturnsNullForMissingFeedback(): void
    {
        $this->repo->method('findById')->willReturn(null);

        self::assertNull($this->service->linkIssue('missing', 'https://github.com/issues/1'));
    }

    /**
     * @return iterable<string, array{FeedbackCategory}>
     */
    public static function provideCategories(): iterable
    {
        foreach (FeedbackCategory::cases() as $category) {
            yield $category->value => [$category];
        }
    }

    #[Test]
    #[DataProvider('provideCategories')]
    public function submitAcceptsAllCategories(FeedbackCategory $category): void
    {
        $this->repo->method('countByUserToday')->willReturn(0);

        $feedback = $this->service->submit(
            'user-001',
            $category,
            'This is a valid feedback submission for testing',
            [],
        );

        self::assertSame($category, $feedback->category);
    }
}
