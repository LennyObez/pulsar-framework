<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\TagService;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;

final class TagServiceTest extends TestCase
{
    private TagRepositoryInterface&Stub $tagRepo;
    private TagService $service;

    protected function setUp(): void
    {
        $this->tagRepo = $this->createStub(TagRepositoryInterface::class);
        $this->service = new TagService($this->tagRepo);
    }

    private function serviceWithMockRepo(): array
    {
        $repo = $this->createMock(TagRepositoryInterface::class);
        $service = new TagService($repo);

        return [$repo, $service];
    }

    #[Test]
    public function create_tag_saves_and_returns_tag(): void
    {
        [$repo, $service] = $this->serviceWithMockRepo();
        $repo->expects(self::once())->method('save');

        $tag = $service->createTag('PHP', 'php', 'PHP language tag');

        self::assertSame('PHP', $tag->name);
        self::assertSame('php', $tag->slug);
        self::assertSame('PHP language tag', $tag->description);
        self::assertSame(0, $tag->usageCount);
    }

    #[Test]
    public function create_tag_with_null_description(): void
    {
        [$repo, $service] = $this->serviceWithMockRepo();
        $repo->expects(self::once())->method('save');

        $tag = $service->createTag('Go', 'go');

        self::assertSame('', $tag->description);
    }

    #[Test]
    public function attach_tags_increments_usage_count(): void
    {
        [$repo, $service] = $this->serviceWithMockRepo();
        $tag = Tag::create(id: 'tag-1', slug: 'php', name: 'PHP');

        $repo->method('findById')->willReturn($tag);
        $repo->expects(self::once())->method('attachToThread');
        $repo->expects(self::once())->method('save');

        $service->attachTags('thread-1', ['tag-1']);
    }

    #[Test]
    public function attach_tags_throws_for_unknown_tag(): void
    {
        $this->tagRepo->method('findById')->willReturn(null);

        $this->expectException(ForumException::class);

        $this->service->attachTags('thread-1', ['missing-tag']);
    }

    #[Test]
    public function detach_tags_decrements_usage_count(): void
    {
        [$repo, $service] = $this->serviceWithMockRepo();
        $tag = new Tag(id: 'tag-1', slug: 'php', name: 'PHP', description: '', usageCount: 5);

        $repo->method('findById')->willReturn($tag);
        $repo->expects(self::once())->method('detachFromThread');
        $repo->expects(self::once())->method('save');

        $service->detachTags('thread-1', ['tag-1']);
    }

    #[Test]
    public function detach_tags_throws_for_unknown_tag(): void
    {
        $this->tagRepo->method('findById')->willReturn(null);

        $this->expectException(ForumException::class);

        $this->service->detachTags('thread-1', ['nonexistent']);
    }

    #[Test]
    public function find_popular_returns_limited_tags(): void
    {
        $tags = [
            Tag::create(id: 't1', slug: 's1', name: 'Tag1'),
            Tag::create(id: 't2', slug: 's2', name: 'Tag2'),
            Tag::create(id: 't3', slug: 's3', name: 'Tag3'),
        ];

        $this->tagRepo->method('findAll')->willReturn($tags);

        $result = $this->service->findPopular(2);

        self::assertCount(2, $result);
    }

    #[Test]
    public function find_popular_returns_all_when_limit_exceeds_count(): void
    {
        $tags = [Tag::create(id: 't1', slug: 's1', name: 'Tag1')];

        $this->tagRepo->method('findAll')->willReturn($tags);

        $result = $this->service->findPopular(10);

        self::assertCount(1, $result);
    }
}
