<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Publishing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Internal\Publishing\PublishingOrchestrator;
use Pulsar\Extension\Cms\Publishing\ChannelRegistry;
use Pulsar\Extension\Cms\Publishing\PublishingChannelInterface;
use Pulsar\Extension\Cms\Publishing\PublishResult;
use Pulsar\Queue\QueueDriverInterface;
use RuntimeException;

#[CoversClass(PublishingOrchestrator::class)]
final class PublishingOrchestratorTest extends TestCase
{
    private ContentTranslationRepositoryInterface&Stub $translationRepo;

    protected function setUp(): void
    {
        $this->translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
    }

    #[Test]
    public function publish_to_all_publishes_to_every_enabled_channel(): void
    {
        $translation = $this->createTranslation();
        $this->translationRepo->method('findByContentId')->willReturn([$translation]);

        $webChannel = $this->createEnabledChannel('web');
        $rssChannel = $this->createEnabledChannel('rss');

        $registry = new ChannelRegistry();
        $registry->register($webChannel);
        $registry->register($rssChannel);

        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);
        $content = $this->createPublishedContent();

        $results = $orchestrator->publishToAll($content);

        self::assertCount(2, $results);
        self::assertTrue($results[0]->success);
        self::assertTrue($results[1]->success);
        self::assertSame('web', $results[0]->channelName);
        self::assertSame('rss', $results[1]->channelName);
    }

    #[Test]
    public function publish_to_all_skips_disabled_channels(): void
    {
        $translation = $this->createTranslation();
        $this->translationRepo->method('findByContentId')->willReturn([$translation]);

        $webChannel = $this->createEnabledChannel('web');
        $disabledChannel = $this->createDisabledChannel('rss');

        $registry = new ChannelRegistry();
        $registry->register($webChannel);
        $registry->register($disabledChannel);

        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);
        $results = $orchestrator->publishToAll($this->createPublishedContent());

        self::assertCount(1, $results);
        self::assertSame('web', $results[0]->channelName);
    }

    #[Test]
    public function publish_to_all_catches_channel_exceptions(): void
    {
        $translation = $this->createTranslation();
        $this->translationRepo->method('findByContentId')->willReturn([$translation]);

        $failingChannel = $this->createFailingChannel('rss', 'Feed generation failed');

        $registry = new ChannelRegistry();
        $registry->register($failingChannel);

        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);
        $results = $orchestrator->publishToAll($this->createPublishedContent());

        self::assertCount(1, $results);
        self::assertFalse($results[0]->success);
        self::assertSame('Feed generation failed', $results[0]->errorMessage);
    }

    #[Test]
    public function publish_to_all_dispatches_to_queue_when_available(): void
    {
        $translation = $this->createTranslation();
        $this->translationRepo->method('findByContentId')->willReturn([$translation]);

        $webChannel = $this->createEnabledChannel('web');
        $registry = new ChannelRegistry();
        $registry->register($webChannel);

        $queueDriver = $this->createStub(QueueDriverInterface::class);
        $queueDriver->method('push')->willReturn('job-1');

        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo, $queueDriver);
        $results = $orchestrator->publishToAll($this->createPublishedContent());

        self::assertCount(1, $results);
        self::assertTrue($results[0]->success);
    }

    #[Test]
    public function unpublish_from_all_unpublishes_all_enabled_channels(): void
    {
        $webChannel = $this->createEnabledChannel('web');
        $rssChannel = $this->createEnabledChannel('rss');

        $registry = new ChannelRegistry();
        $registry->register($webChannel);
        $registry->register($rssChannel);

        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);
        $results = $orchestrator->unpublishFromAll($this->createPublishedContent());

        self::assertCount(2, $results);
        self::assertTrue($results[0]->success);
        self::assertTrue($results[1]->success);
    }

    #[Test]
    public function publish_to_channel_returns_failure_for_unknown_channel(): void
    {
        $registry = new ChannelRegistry();
        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);

        $result = $orchestrator->publishToChannel($this->createPublishedContent(), 'nonexistent');

        self::assertFalse($result->success);
        self::assertStringContainsString('not found', $result->errorMessage ?? '');
    }

    #[Test]
    public function publish_to_channel_returns_failure_for_disabled_channel(): void
    {
        $disabledChannel = $this->createDisabledChannel('rss');
        $registry = new ChannelRegistry();
        $registry->register($disabledChannel);

        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);
        $result = $orchestrator->publishToChannel($this->createPublishedContent(), 'rss');

        self::assertFalse($result->success);
        self::assertStringContainsString('not enabled', $result->errorMessage ?? '');
    }

    #[Test]
    public function publish_to_channel_returns_failure_when_no_translations(): void
    {
        $this->translationRepo->method('findByContentId')->willReturn([]);

        $webChannel = $this->createEnabledChannel('web');
        $registry = new ChannelRegistry();
        $registry->register($webChannel);

        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);
        $result = $orchestrator->publishToChannel($this->createPublishedContent(), 'web');

        self::assertFalse($result->success);
        self::assertStringContainsString('No translations', $result->errorMessage ?? '');
    }

    #[Test]
    public function publish_to_channel_succeeds_with_valid_channel(): void
    {
        $translation = $this->createTranslation();
        $this->translationRepo->method('findByContentId')->willReturn([$translation]);

        $webChannel = $this->createEnabledChannel('web');
        $registry = new ChannelRegistry();
        $registry->register($webChannel);

        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);
        $result = $orchestrator->publishToChannel($this->createPublishedContent(), 'web');

        self::assertTrue($result->success);
        self::assertSame('web', $result->channelName);
    }

    #[Test]
    public function publish_to_all_returns_empty_when_no_channels(): void
    {
        $this->translationRepo->method('findByContentId')->willReturn([$this->createTranslation()]);

        $registry = new ChannelRegistry();
        $orchestrator = new PublishingOrchestrator($registry, $this->translationRepo);

        $results = $orchestrator->publishToAll($this->createPublishedContent());

        self::assertSame([], $results);
    }

    private function createPublishedContent(): Content
    {
        $content = Content::create(
            id: '01912345-6789-7abc-8def-0123456789ab',
            contentType: ContentType::Article,
            authorId: '01912345-6789-7abc-8def-0123456789cd',
        );

        return $content->publish();
    }

    private function createTranslation(): ContentTranslation
    {
        return new ContentTranslation(
            id: '01912345-0000-7abc-8def-aaaaaaaaaaaa',
            contentId: '01912345-6789-7abc-8def-0123456789ab',
            locale: 'en',
            title: 'Hello World',
            slugSegment: 'hello-world',
            path: 'blog/hello-world',
            body: '<p>Hello</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Hello',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }

    private function createEnabledChannel(string $name): PublishingChannelInterface
    {
        return new class ($name) implements PublishingChannelInterface {
            public function __construct(private readonly string $channelName) {}

            public function name(): string
            {
                return $this->channelName;
            }

            public function publish(Content $content, ContentTranslation $translation): PublishResult
            {
                return PublishResult::success($this->channelName);
            }

            public function unpublish(Content $content): PublishResult
            {
                return PublishResult::success($this->channelName);
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }

    private function createDisabledChannel(string $name): PublishingChannelInterface
    {
        return new class ($name) implements PublishingChannelInterface {
            public function __construct(private readonly string $channelName) {}

            public function name(): string
            {
                return $this->channelName;
            }

            public function publish(Content $content, ContentTranslation $translation): PublishResult
            {
                return PublishResult::success($this->channelName);
            }

            public function unpublish(Content $content): PublishResult
            {
                return PublishResult::success($this->channelName);
            }

            public function isEnabled(): bool
            {
                return false;
            }
        };
    }

    private function createFailingChannel(string $name, string $error): PublishingChannelInterface
    {
        return new class ($name, $error) implements PublishingChannelInterface {
            public function __construct(
                private readonly string $channelName,
                private readonly string $error,
            ) {}

            public function name(): string
            {
                return $this->channelName;
            }

            public function publish(Content $content, ContentTranslation $translation): PublishResult
            {
                throw new RuntimeException($this->error);
            }

            public function unpublish(Content $content): PublishResult
            {
                return PublishResult::success($this->channelName);
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }
}
