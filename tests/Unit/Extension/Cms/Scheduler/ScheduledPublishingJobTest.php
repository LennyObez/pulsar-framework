<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Scheduler\ScheduledPublishingJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;
use RuntimeException;

#[CoversClass(ScheduledPublishingJob::class)]
final class ScheduledPublishingJobTest extends TestCase
{
    #[Test]
    public function jobNameIsCorrect(): void
    {
        /** @var ContentRepositoryInterface&Stub $repo */
        $repo = $this->createStub(ContentRepositoryInterface::class);

        $job = new ScheduledPublishingJob($repo);

        self::assertSame('cms:scheduled-publishing', $job->getName());
    }

    #[Test]
    public function scheduleIsEveryMinute(): void
    {
        /** @var ContentRepositoryInterface&Stub $repo */
        $repo = $this->createStub(ContentRepositoryInterface::class);

        $job = new ScheduledPublishingJob($repo);

        self::assertSame('* * * * *', $job->getSchedule()->expression);
    }

    #[Test]
    public function descriptionIsNotEmpty(): void
    {
        /** @var ContentRepositoryInterface&Stub $repo */
        $repo = $this->createStub(ContentRepositoryInterface::class);

        $job = new ScheduledPublishingJob($repo);

        self::assertNotEmpty($job->getDescription());
    }

    #[Test]
    public function executePublishesScheduledContent(): void
    {
        $scheduledContent = $this->createScheduledContent();

        /** @var ContentRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(ContentRepositoryInterface::class);
        $repo->method('findScheduledForPublishing')->willReturn([$scheduledContent]);
        $repo->method('findScheduledForUnpublishing')->willReturn([]);
        $repo->expects(self::once())->method('save');

        $job = new ScheduledPublishingJob($repo);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('1 published', $result->output);
        self::assertStringContainsString('0 archived', $result->output);
    }

    #[Test]
    public function executeArchivesExpiredContent(): void
    {
        $publishedContent = $this->createPublishedContent();

        /** @var ContentRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(ContentRepositoryInterface::class);
        $repo->method('findScheduledForPublishing')->willReturn([]);
        $repo->method('findScheduledForUnpublishing')->willReturn([$publishedContent]);
        $repo->expects(self::once())->method('save');

        $job = new ScheduledPublishingJob($repo);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('0 published', $result->output);
        self::assertStringContainsString('1 archived', $result->output);
    }

    #[Test]
    public function executeWithNothingToDoReturnsSuccess(): void
    {
        /** @var ContentRepositoryInterface&Stub $repo */
        $repo = $this->createStub(ContentRepositoryInterface::class);
        $repo->method('findScheduledForPublishing')->willReturn([]);
        $repo->method('findScheduledForUnpublishing')->willReturn([]);

        $job = new ScheduledPublishingJob($repo);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('0 published', $result->output);
        self::assertStringContainsString('0 archived', $result->output);
        self::assertStringContainsString('0 total', $result->output);
    }

    #[Test]
    public function executeReportsFailureOnException(): void
    {
        /** @var ContentRepositoryInterface&Stub $repo */
        $repo = $this->createStub(ContentRepositoryInterface::class);
        $repo->method('findScheduledForPublishing')->willThrowException(new RuntimeException('Database unreachable'));

        $job = new ScheduledPublishingJob($repo);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Failure, $result->status);
        self::assertNotNull($result->exception);
        self::assertSame('Database unreachable', $result->exception->getMessage());
    }

    #[Test]
    public function executeHandlesBothPublishAndArchiveInSameRun(): void
    {
        $scheduledContent = $this->createScheduledContent();
        $publishedContent = $this->createPublishedContent();

        /** @var ContentRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(ContentRepositoryInterface::class);
        $repo->method('findScheduledForPublishing')->willReturn([$scheduledContent]);
        $repo->method('findScheduledForUnpublishing')->willReturn([$publishedContent]);
        $repo->expects(self::exactly(2))->method('save');

        $job = new ScheduledPublishingJob($repo);
        $result = $job->execute($this->createContext());

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('1 published', $result->output);
        self::assertStringContainsString('1 archived', $result->output);
        self::assertStringContainsString('2 total', $result->output);
    }

    private function createScheduledContent(): Content
    {
        $now = new DateTimeImmutable();

        return new Content(
            id: '01912345-6789-7abc-def0-123456789abc',
            tenantId: null,
            contentType: ContentType::Article,
            authorId: '01900000-0000-7000-8000-000000000001',
            status: PublishingStatus::Scheduled,
            scheduledPublishAt: $now->modify('-1 hour'),
            scheduledUnpublishAt: null,
            publishedAt: null,
            createdAt: $now->modify('-1 day'),
            updatedAt: $now->modify('-1 hour'),
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );
    }

    private function createPublishedContent(): Content
    {
        $now = new DateTimeImmutable();

        return new Content(
            id: '01912345-6789-7abc-def0-123456789def',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: '01900000-0000-7000-8000-000000000001',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: $now->modify('-1 hour'),
            publishedAt: $now->modify('-7 days'),
            createdAt: $now->modify('-14 days'),
            updatedAt: $now->modify('-7 days'),
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );
    }

    private function createContext(): JobContext
    {
        return new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );
    }
}
