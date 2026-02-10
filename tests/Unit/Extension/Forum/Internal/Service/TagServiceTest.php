<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\TagService;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;

#[CoversClass(TagService::class)]
final class TagServiceTest extends TestCase
{
    private TagRepositoryInterface&Stub $tags;

    protected function setUp(): void
    {
        $this->tags = $this->createStub(TagRepositoryInterface::class);
    }

    private function makeService(?TagRepositoryInterface $tags = null): TagService
    {
        return new TagService(tags: $tags ?? $this->tags);
    }

    #[Test]
    public function createTagSavesAndReturnsTag(): void
    {
        $tags = $this->createMock(TagRepositoryInterface::class);
        $tags->expects(self::once())->method('save')->with(self::isInstanceOf(Tag::class));

        $service = $this->makeService($tags);

        $tag = $service->createTag('PHP', 'php', 'PHP programming language');

        self::assertSame('PHP', $tag->name);
        self::assertSame('php', $tag->slug);
        self::assertSame('PHP programming language', $tag->description);
        self::assertSame(0, $tag->usageCount);
    }

    #[Test]
    public function createTagWithNullDescription(): void
    {
        $tags = $this->createMock(TagRepositoryInterface::class);
        $tags->expects(self::once())->method('save');

        $service = $this->makeService($tags);

        $tag = $service->createTag('PHP', 'php');

        self::assertSame('', $tag->description);
    }

    #[Test]
    public function attachTagsAttachesAndIncrementsUsage(): void
    {
        $tag1 = Tag::create('tag-1', 'php', 'PHP');
        $tag2 = Tag::create('tag-2', 'laravel', 'Laravel');

        $tags = $this->createMock(TagRepositoryInterface::class);
        $tags->method('findById')
            ->willReturnCallback(fn(string $id) => match ($id) {
                'tag-1' => $tag1,
                'tag-2' => $tag2,
                default => null,
            });

        $tags->expects(self::exactly(2))->method('attachToThread');
        $tags->expects(self::exactly(2))->method('save');

        $service = $this->makeService($tags);

        $service->attachTags('thread-1', ['tag-1', 'tag-2']);
    }

    #[Test]
    public function attachTagsThrowsWhenTagNotFound(): void
    {
        $this->tags->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Tag not found');

        $service->attachTags('thread-1', ['missing']);
    }

    #[Test]
    public function detachTagsDetachesAndDecrementsUsage(): void
    {
        $tag = Tag::create('tag-1', 'php', 'PHP');

        $tags = $this->createMock(TagRepositoryInterface::class);
        $tags->method('findById')->willReturn($tag);
        $tags->expects(self::once())->method('detachFromThread')->with('tag-1', 'thread-1');
        $tags->expects(self::once())->method('save');

        $service = $this->makeService($tags);

        $service->detachTags('thread-1', ['tag-1']);
    }

    #[Test]
    public function detachTagsThrowsWhenTagNotFound(): void
    {
        $this->tags->method('findById')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Tag not found');

        $service->detachTags('thread-1', ['missing']);
    }

    #[Test]
    public function findPopularReturnsLimitedTags(): void
    {
        $tags = [];
        for ($i = 0; $i < 30; $i++) {
            $tags[] = Tag::create("tag-{$i}", "tag-{$i}", "Tag {$i}");
        }

        $this->tags->method('findAll')->willReturn($tags);

        $service = $this->makeService();

        $result = $service->findPopular(10);

        self::assertCount(10, $result);
    }

    #[Test]
    public function findPopularReturnsAllWhenFewerThanLimit(): void
    {
        $tags = [
            Tag::create('tag-1', 'php', 'PHP'),
            Tag::create('tag-2', 'js', 'JavaScript'),
        ];

        $this->tags->method('findAll')->willReturn($tags);

        $service = $this->makeService();

        $result = $service->findPopular(20);

        self::assertCount(2, $result);
    }

    #[Test]
    public function findPopularDefaultsToTwentyLimit(): void
    {
        $tags = [];
        for ($i = 0; $i < 25; $i++) {
            $tags[] = Tag::create("tag-{$i}", "tag-{$i}", "Tag {$i}");
        }

        $this->tags->method('findAll')->willReturn($tags);

        $service = $this->makeService();

        $result = $service->findPopular();

        self::assertCount(20, $result);
    }
}
