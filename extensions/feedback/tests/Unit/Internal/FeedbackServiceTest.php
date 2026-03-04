<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Tests\Unit\Internal;

use InvalidArgumentException;
use OverflowException;
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
    private FeedbackRepositoryInterface&Stub $repository;
    private FeedbackService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(FeedbackRepositoryInterface::class);
        $this->service = new FeedbackService($this->repository);
    }

    #[Test]
    public function submitCreatesFeedbackAndSaves(): void
    {
        $this->repository->method('countByUserToday')->willReturn(0);

        $result = $this->service->submit(
            'user-1',
            FeedbackCategory::Bug,
            'This is a valid description that is long enough',
            ['page' => '/test'],
        );

        self::assertInstanceOf(Feedback::class, $result);
        self::assertSame('user-1', $result->userId);
        self::assertSame(FeedbackCategory::Bug, $result->category);
        self::assertSame(FeedbackStatus::Received, $result->status);
    }

    #[Test]
    public function submitRejectsDescriptionTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Description must be between');

        $this->service->submit('user-1', FeedbackCategory::Bug, 'Short', []);
    }

    #[Test]
    public function submitRejectsDescriptionTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Description must be between');

        $this->service->submit('user-1', FeedbackCategory::Bug, str_repeat('x', 5001), []);
    }

    #[Test]
    public function submitAcceptsDescriptionAtMinimumLength(): void
    {
        $this->repository->method('countByUserToday')->willReturn(0);

        $result = $this->service->submit('user-1', FeedbackCategory::Bug, str_repeat('a', 10), []);

        self::assertSame(10, mb_strlen($result->description));
    }

    #[Test]
    public function submitAcceptsDescriptionAtMaximumLength(): void
    {
        $this->repository->method('countByUserToday')->willReturn(0);

        $result = $this->service->submit('user-1', FeedbackCategory::Bug, str_repeat('b', 5000), []);

        self::assertSame(5000, mb_strlen($result->description));
    }

    #[Test]
    public function submitRejectsWhenRateLimitExceeded(): void
    {
        $this->repository->method('countByUserToday')->willReturn(10);

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->service->submit('user-1', FeedbackCategory::Bug, 'Valid description for feedback', []);
    }

    #[Test]
    public function submitAllowsUpToNineSubmissions(): void
    {
        $this->repository->method('countByUserToday')->willReturn(9);

        $result = $this->service->submit('user-1', FeedbackCategory::Bug, 'Valid description for feedback', []);

        self::assertInstanceOf(Feedback::class, $result);
    }

    #[Test]
    public function updateStatusReturnsUpdatedFeedback(): void
    {
        $feedback = Feedback::create('user-1', FeedbackCategory::Bug, 'Some valid description text');
        $this->repository->method('findById')->willReturn($feedback);

        $result = $this->service->updateStatus($feedback->id, FeedbackStatus::Investigating);

        self::assertNotNull($result);
        self::assertSame(FeedbackStatus::Investigating, $result->status);
    }

    #[Test]
    public function updateStatusReturnsNullWhenNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $result = $this->service->updateStatus('nonexistent', FeedbackStatus::Resolved);

        self::assertNull($result);
    }

    #[Test]
    public function addResponseReturnsUpdatedFeedback(): void
    {
        $feedback = Feedback::create('user-1', FeedbackCategory::Feature, 'Valid feature request description');
        $this->repository->method('findById')->willReturn($feedback);

        $result = $this->service->addResponse($feedback->id, 'Thank you for reporting');

        self::assertNotNull($result);
        self::assertSame('Thank you for reporting', $result->adminResponse);
    }

    #[Test]
    public function addResponseReturnsNullWhenNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $result = $this->service->addResponse('nonexistent', 'Response');

        self::assertNull($result);
    }

    #[Test]
    public function linkIssueReturnsUpdatedFeedback(): void
    {
        $feedback = Feedback::create('user-1', FeedbackCategory::Bug, 'A valid bug description');
        $this->repository->method('findById')->willReturn($feedback);

        $result = $this->service->linkIssue($feedback->id, 'https://github.com/org/repo/issues/1');

        self::assertNotNull($result);
        self::assertSame('https://github.com/org/repo/issues/1', $result->githubIssueUrl);
    }

    #[Test]
    public function linkIssueReturnsNullWhenNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $result = $this->service->linkIssue('nonexistent', 'https://example.com');

        self::assertNull($result);
    }
}
