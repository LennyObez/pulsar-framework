<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Internal\Cache\CachedContentRepository;

#[CoversClass(CachedContentRepository::class)]
final class CachedContentRepositoryTest extends TestCase
{
    private ContentRepositoryInterface&Stub $inner;
    private TaggedCacheInterface&Stub $cache;
    private CachedContentRepository $sut;

    protected function setUp(): void
    {
        $this->inner = $this->createStub(ContentRepositoryInterface::class);
        $this->cache = $this->createStub(TaggedCacheInterface::class);
        $this->sut = new CachedContentRepository($this->inner, $this->cache);
    }

    #[Test]
    public function findById_returns_cached_content_on_hit(): void
    {
        $content = $this->makeContent('abc-123');
        $this->cache->method('get')->willReturn($content);

        $result = $this->sut->findById('abc-123');

        self::assertSame($content, $result);
    }

    #[Test]
    public function findById_delegates_to_inner_on_miss(): void
    {
        $content = $this->makeContent('abc-123');
        $this->cache->method('get')->willReturn(null);
        $this->inner->method('findById')->willReturn($content);

        $result = $this->sut->findById('abc-123');

        self::assertSame($content, $result);
    }

    #[Test]
    public function findById_stores_in_cache_on_miss(): void
    {
        $content = $this->makeContent('abc-123');

        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::once())
            ->method('set')
            ->with('cms_content_id:abc-123', $content, ['cms_content:abc-123', 'cms_content'], 60);

        $this->inner->method('findById')->willReturn($content);

        $sut = new CachedContentRepository($this->inner, $cache);
        $sut->findById('abc-123');
    }

    #[Test]
    public function findById_does_not_cache_null(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::never())->method('set');

        $this->inner->method('findById')->willReturn(null);

        $sut = new CachedContentRepository($this->inner, $cache);
        $result = $sut->findById('missing');

        self::assertNull($result);
    }

    #[Test]
    public function findByPath_returns_cached_content_on_hit(): void
    {
        $content = $this->makeContent('def-456');
        $this->cache->method('get')->willReturn($content);

        $result = $this->sut->findByPath('en', '/about');

        self::assertSame($content, $result);
    }

    #[Test]
    public function findByPath_delegates_to_inner_on_miss(): void
    {
        $content = $this->makeContent('def-456');
        $this->cache->method('get')->willReturn(null);
        $this->inner->method('findByPath')->willReturn($content);

        $result = $this->sut->findByPath('en', '/about');

        self::assertSame($content, $result);
    }

    #[Test]
    public function save_invalidates_content_cache_tag(): void
    {
        $content = $this->makeContent('abc-123');

        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_content:abc-123');

        $sut = new CachedContentRepository($this->inner, $cache);
        $sut->save($content);
    }

    #[Test]
    public function delete_invalidates_content_cache_tag(): void
    {
        $content = $this->makeContent('abc-123');

        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_content:abc-123');

        $sut = new CachedContentRepository($this->inner, $cache);
        $sut->delete($content);
    }

    #[Test]
    public function findPublished_delegates_directly_without_caching(): void
    {
        $this->inner->method('findPublished')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 20),
        );

        $result = $this->sut->findPublished('en');

        self::assertSame(0, $result->total);
    }

    #[Test]
    public function findByIds_delegates_directly_without_caching(): void
    {
        $content = $this->makeContent('abc-123');
        $this->inner->method('findByIds')->willReturn(['abc-123' => $content]);

        $result = $this->sut->findByIds(['abc-123']);

        self::assertSame($content, $result['abc-123']);
    }

    private function makeContent(string $id): Content
    {
        return Content::create(
            id: $id,
            contentType: ContentType::Page,
            authorId: 'author-1',
        );
    }
}
