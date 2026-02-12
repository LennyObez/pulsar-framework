<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Cms\Command\CmsPublishScheduledCommand;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;

#[CoversClass(CmsPublishScheduledCommand::class)]
final class CmsPublishScheduledCommandTest extends TestCase
{
    private const string CONTENT_ID_1 = '01912345-6789-7abc-8def-0123456789a1';
    private const string CONTENT_ID_2 = '01912345-6789-7abc-8def-0123456789a2';
    private const string AUTHOR_ID = '01912345-6789-7abc-8def-0123456789cd';

    private ContentRepositoryInterface&Stub $contentRepository;
    private InputInterface&Stub $input;
    private OutputInterface&Stub $output;

    protected function setUp(): void
    {
        $this->contentRepository = $this->createStub(ContentRepositoryInterface::class);
        $this->input = $this->createStub(InputInterface::class);
        $this->output = $this->createStub(OutputInterface::class);
    }

    #[Test]
    public function publishes_content_with_past_scheduled_publish_at(): void
    {
        $pastDate = new DateTimeImmutable('-1 hour');
        $scheduled = $this->buildContent(
            self::CONTENT_ID_1,
            PublishingStatus::Scheduled,
            scheduledPublishAt: $pastDate,
        );

        $this->contentRepository->method('findScheduledForPublishing')->willReturn([$scheduled]);
        $this->contentRepository->method('findScheduledForUnpublishing')->willReturn([]);

        $saved = [];
        $this->contentRepository->method('save')->willReturnCallback(
            static function (Content $content) use (&$saved): void {
                $saved[] = $content;
            },
        );

        $command = new CmsPublishScheduledCommand($this->contentRepository);
        $exitCode = $command->execute($this->input, $this->output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertCount(1, $saved);
        self::assertSame(PublishingStatus::Published, $saved[0]->status);
        self::assertNotNull($saved[0]->publishedAt);
    }

    #[Test]
    public function archives_content_with_past_scheduled_unpublish_at(): void
    {
        $pastDate = new DateTimeImmutable('-30 minutes');
        $published = $this->buildContent(
            self::CONTENT_ID_2,
            PublishingStatus::Published,
            scheduledUnpublishAt: $pastDate,
            publishedAt: new DateTimeImmutable('-7 days'),
        );

        $this->contentRepository->method('findScheduledForPublishing')->willReturn([]);
        $this->contentRepository->method('findScheduledForUnpublishing')->willReturn([$published]);

        $saved = [];
        $this->contentRepository->method('save')->willReturnCallback(
            static function (Content $content) use (&$saved): void {
                $saved[] = $content;
            },
        );

        $command = new CmsPublishScheduledCommand($this->contentRepository);
        $exitCode = $command->execute($this->input, $this->output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertCount(1, $saved);
        self::assertSame(PublishingStatus::Archived, $saved[0]->status);
    }

    #[Test]
    public function handles_no_scheduled_content(): void
    {
        $this->contentRepository->method('findScheduledForPublishing')->willReturn([]);
        $this->contentRepository->method('findScheduledForUnpublishing')->willReturn([]);

        $command = new CmsPublishScheduledCommand($this->contentRepository);
        $exitCode = $command->execute($this->input, $this->output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function handles_both_publish_and_unpublish_in_single_run(): void
    {
        $scheduled = $this->buildContent(
            self::CONTENT_ID_1,
            PublishingStatus::Scheduled,
            scheduledPublishAt: new DateTimeImmutable('-1 hour'),
        );
        $published = $this->buildContent(
            self::CONTENT_ID_2,
            PublishingStatus::Published,
            scheduledUnpublishAt: new DateTimeImmutable('-30 minutes'),
            publishedAt: new DateTimeImmutable('-7 days'),
        );

        $this->contentRepository->method('findScheduledForPublishing')->willReturn([$scheduled]);
        $this->contentRepository->method('findScheduledForUnpublishing')->willReturn([$published]);

        $saved = [];
        $this->contentRepository->method('save')->willReturnCallback(
            static function (Content $content) use (&$saved): void {
                $saved[] = $content;
            },
        );

        $command = new CmsPublishScheduledCommand($this->contentRepository);
        $exitCode = $command->execute($this->input, $this->output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertCount(2, $saved);
        self::assertSame(PublishingStatus::Published, $saved[0]->status);
        self::assertSame(PublishingStatus::Archived, $saved[1]->status);
    }

    private function buildContent(
        string $id,
        PublishingStatus $status,
        ?DateTimeImmutable $scheduledPublishAt = null,
        ?DateTimeImmutable $scheduledUnpublishAt = null,
        ?DateTimeImmutable $publishedAt = null,
    ): Content {
        $now = new DateTimeImmutable();

        return new Content(
            id: $id,
            tenantId: null,
            contentType: ContentType::Article,
            authorId: self::AUTHOR_ID,
            status: $status,
            scheduledPublishAt: $scheduledPublishAt,
            scheduledUnpublishAt: $scheduledUnpublishAt,
            publishedAt: $publishedAt,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );
    }
}
