<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\CachedPage;
use Pulsar\Rendering\IncrementalRegenerator;
use Pulsar\Rendering\IsrResult;
use Pulsar\Rendering\PageRendererInterface;
use ReflectionProperty;

#[CoversClass(IncrementalRegenerator::class)]
#[CoversClass(CachedPage::class)]
#[CoversClass(IsrResult::class)]
final class IncrementalRegeneratorTest extends TestCase
{
    #[Test]
    public function cache_miss_renders_and_caches(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<h1>Fresh</h1>');

        $isr = new IncrementalRegenerator($renderer, revalidateAfterSeconds: 60);
        $result = $isr->getPage('/');

        self::assertSame('<h1>Fresh</h1>', $result->html);
        self::assertFalse($result->hit);
        self::assertFalse($result->stale);
        self::assertSame('/', $result->path);
        self::assertSame(1, $isr->cacheSize());
    }

    #[Test]
    public function cache_hit_returns_cached_html(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<h1>Content</h1>');

        $isr = new IncrementalRegenerator($renderer, revalidateAfterSeconds: 3600);

        $isr->getPage('/about');
        $result = $isr->getPage('/about');

        self::assertSame('<h1>Content</h1>', $result->html);
        self::assertTrue($result->hit);
        self::assertFalse($result->stale);
    }

    #[Test]
    public function stale_page_is_served_with_stale_flag(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<h1>Old</h1>');

        // Use 1-second revalidation
        $isr = new IncrementalRegenerator($renderer, revalidateAfterSeconds: 1);
        $isr->getPage('/page');

        // Backdate the cache entry to make it stale
        $this->backdateCache($isr, '/page', 10);

        $result = $isr->getPage('/page');

        self::assertTrue($result->hit);
        self::assertTrue($result->stale);
    }

    #[Test]
    public function regenerate_updates_cache(): void
    {
        $callCount = 0;
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            function () use (&$callCount): string {
                $callCount++;

                return "<p>Version {$callCount}</p>";
            },
        );

        $isr = new IncrementalRegenerator($renderer);
        $isr->getPage('/page');
        $isr->regenerate('/page');

        $result = $isr->getPage('/page');
        self::assertSame('<p>Version 2</p>', $result->html);
        self::assertTrue($result->hit);
    }

    #[Test]
    public function invalidate_tag_removes_tagged_pages(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $isr = new IncrementalRegenerator($renderer);
        $isr->getPage('/blog/1', ['blog']);
        $isr->getPage('/blog/2', ['blog']);
        $isr->getPage('/about', ['pages']);

        self::assertSame(3, $isr->cacheSize());

        $invalidated = $isr->invalidateTag('blog');

        self::assertContains('/blog/1', $invalidated);
        self::assertContains('/blog/2', $invalidated);
        self::assertCount(2, $invalidated);
        self::assertSame(1, $isr->cacheSize());
    }

    #[Test]
    public function invalidate_tag_returns_empty_for_unknown_tag(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $isr = new IncrementalRegenerator($renderer);

        $invalidated = $isr->invalidateTag('nonexistent');

        self::assertSame([], $invalidated);
    }

    #[Test]
    public function invalidate_path_removes_single_page(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $isr = new IncrementalRegenerator($renderer);
        $isr->getPage('/a');
        $isr->getPage('/b');

        self::assertSame(2, $isr->cacheSize());

        $isr->invalidatePath('/a');

        self::assertSame(1, $isr->cacheSize());

        // /a is now a miss
        $result = $isr->getPage('/a');
        self::assertFalse($result->hit);
    }

    #[Test]
    public function stale_paths_returns_expired_entries(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $isr = new IncrementalRegenerator($renderer, revalidateAfterSeconds: 1);
        $isr->getPage('/x');
        $isr->getPage('/y');

        // Backdate both entries to make them stale
        $this->backdateCache($isr, '/x', 10);
        $this->backdateCache($isr, '/y', 10);

        $stale = $isr->stalePaths();
        self::assertContains('/x', $stale);
        self::assertContains('/y', $stale);
    }

    #[Test]
    public function cache_size_tracks_entries(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $isr = new IncrementalRegenerator($renderer);

        self::assertSame(0, $isr->cacheSize());

        $isr->getPage('/a');
        self::assertSame(1, $isr->cacheSize());

        $isr->getPage('/b');
        self::assertSame(2, $isr->cacheSize());

        $isr->invalidatePath('/a');
        self::assertSame(1, $isr->cacheSize());
    }

    #[Test]
    public function tags_accumulate_across_regenerations(): void
    {
        $renderer = $this->createStub(PageRendererInterface::class);
        $renderer->method('render')->willReturn('<p>ok</p>');

        $isr = new IncrementalRegenerator($renderer);
        $isr->regenerate('/page', ['tag-a']);
        $isr->regenerate('/page', ['tag-b']);

        // Invalidating tag-a should still find /page since it was tagged
        $invalidated = $isr->invalidateTag('tag-a');
        self::assertContains('/page', $invalidated);
    }

    /**
     * Backdate a cache entry by the given number of seconds so staleness tests are deterministic.
     */
    private function backdateCache(IncrementalRegenerator $isr, string $path, int $seconds): void
    {
        $cacheRef = new ReflectionProperty($isr, 'cache');

        /** @var array<string, CachedPage> $cache */
        $cache = $cacheRef->getValue($isr);

        if (isset($cache[$path])) {
            $cache[$path] = new CachedPage($cache[$path]->html, time() - $seconds);
            $cacheRef->setValue($isr, $cache);
        }
    }
}
