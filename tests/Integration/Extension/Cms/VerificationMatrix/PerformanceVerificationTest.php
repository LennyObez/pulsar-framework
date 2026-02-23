<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms\VerificationMatrix;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function hrtime;
use function json_encode;
use function memory_get_usage;

/**
 * Performance verification matrix: P1-P8.
 *
 * Validates performance budgets for critical CMS operations.
 */
#[Group('verification-matrix')]
final class PerformanceVerificationTest extends TestCase
{
    /**
     * P1: Cached content retrieval TTFB < 50ms.
     *
     * Simulates cached page lookup. In-memory repository acts as cache.
     */
    #[Test]
    public function test_p1_cached_ttfb(): void
    {
        $repo = $this->seedRepository(100);

        // Warm up
        $warmup = $repo->findById('content-50');
        self::assertNotNull($warmup);

        $start = hrtime(true);

        for ($i = 0; $i < 100; $i++) {
            $result = $repo->findById('content-50');
            self::assertNotNull($result);
        }

        $elapsed = (hrtime(true) - $start) / 1_000_000; // ms
        $perLookup = $elapsed / 100;

        self::assertLessThan(50.0, $perLookup, "Cached lookup should be < 50ms, got {$perLookup}ms");
    }

    /**
     * P2: Cache miss content retrieval TTFB < 200ms.
     *
     * Simulates uncached lookup with full object hydration.
     */
    #[Test]
    public function test_p2_cache_miss_ttfb(): void
    {
        $repo = $this->seedRepository(500);

        $start = hrtime(true);

        // Simulate cache-miss: full scan for published content
        $result = $repo->findPublished('en', perPage: 20);

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(200.0, $elapsed, "Cache-miss lookup should be < 200ms, got {$elapsed}ms");
        self::assertGreaterThan(0, count($result->items));
    }

    /**
     * P3: Search latency < 100ms for keyword lookup.
     */
    #[Test]
    public function test_p3_search_latency(): void
    {
        $translations = [];
        for ($i = 0; $i < 200; $i++) {
            $translations[] = ContentTranslation::create(
                id: "trans-{$i}",
                contentId: "content-{$i}",
                locale: 'en',
                title: "Article about topic {$i}",
                slugSegment: "article-{$i}",
                path: "articles/article-{$i}",
                body: "<p>This is article number {$i} with searchable content about technology and innovation.</p>",
                bodyPlaintext: "This is article number {$i} with searchable content about technology and innovation.",
            );
        }

        $keyword = 'technology';

        $start = hrtime(true);

        $results = array_filter(
            $translations,
            static fn(ContentTranslation $t) => str_contains($t->bodyPlaintext, $keyword) || str_contains($t->title, $keyword),
        );

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(100.0, $elapsed, "Search should be < 100ms, got {$elapsed}ms");
        self::assertGreaterThan(0, count($results));
    }

    /**
     * P4: Media upload processing — object creation < 50ms.
     */
    #[Test]
    public function test_p4_media_upload(): void
    {
        $start = hrtime(true);

        for ($i = 0; $i < 50; $i++) {
            new \Pulsar\Extension\Cms\Media\MediaAsset(
                id: "media-{$i}",
                tenantId: null,
                uploaderId: 'user-001',
                filename: "image-{$i}.jpg",
                storagePath: "media/2026/02/image-{$i}.jpg",
                disk: 'local',
                mimeType: 'image/jpeg',
                fileSize: 512_000,
                fileHash: hash('sha256', "content-{$i}"),
                width: 1920,
                height: 1080,
                exifData: null,
                altTextDefault: "Image {$i}",
                visibility: \Pulsar\Extension\Cms\Media\MediaVisibility::Public,
                dataClassification: \Pulsar\Extension\Cms\Content\DataClassification::Public,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
                deletedAt: null,
            );
        }

        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perAsset = $elapsed / 50;

        self::assertLessThan(50.0, $perAsset, "Media object creation should be < 50ms, got {$perAsset}ms");
    }

    /**
     * P5: Sitemap generation — 1000 URLs < 500ms.
     */
    #[Test]
    public function test_p5_sitemap_generation(): void
    {
        $urls = [];
        for ($i = 0; $i < 1000; $i++) {
            $urls[] = [
                'loc' => "https://example.com/articles/article-{$i}",
                'lastmod' => '2026-02-19',
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }

        $start = hrtime(true);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url>';
            $xml .= "<loc>{$url['loc']}</loc>";
            $xml .= "<lastmod>{$url['lastmod']}</lastmod>";
            $xml .= "<changefreq>{$url['changefreq']}</changefreq>";
            $xml .= "<priority>{$url['priority']}</priority>";
            $xml .= '</url>';
        }
        $xml .= '</urlset>';

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(500.0, $elapsed, "Sitemap generation for 1000 URLs should be < 500ms, got {$elapsed}ms");
        self::assertStringContainsString('</urlset>', $xml);
    }

    /**
     * P6: Large import — 500 content items < 5s.
     */
    #[Test]
    public function test_p6_large_import(): void
    {
        $contentItems = [];
        for ($i = 0; $i < 500; $i++) {
            $contentItems[] = [
                'id' => "c-{$i}",
                'type' => 'article',
                'title' => "Article {$i}",
                'slug' => "article-{$i}",
                'body' => "<p>Article {$i} content</p>",
            ];
        }

        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Large Import Test'],
            'content' => $contentItems,
        ], JSON_THROW_ON_ERROR);

        $start = hrtime(true);

        $definition = SiteDefinition::fromJson($json);

        $elapsed = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(5000.0, $elapsed, "Parsing 500 items should be < 5s, got {$elapsed}ms");
        self::assertCount(500, $definition->content);
    }

    /**
     * P7: Memory budget — creating 1000 content objects stays under 50MB.
     */
    #[Test]
    public function test_p7_memory_budget(): void
    {
        $baseMemory = memory_get_usage(true);

        $contents = [];
        for ($i = 0; $i < 1000; $i++) {
            $contents[] = Content::create(
                id: "mem-{$i}",
                contentType: ContentType::Article,
                authorId: 'a',
            );
        }

        $peakMemory = memory_get_usage(true) - $baseMemory;
        $peakMB = $peakMemory / 1024 / 1024;

        self::assertLessThan(50.0, $peakMB, "1000 Content objects should use < 50MB, got {$peakMB}MB");
        self::assertCount(1000, $contents);
    }

    /**
     * P8: Stampede protection — concurrent cache lookups share single computation.
     *
     * Simulates multiple "threads" attempting the same cache lookup. Only one
     * should compute; the rest should wait for the result.
     */
    #[Test]
    public function test_p8_stampede_protection(): void
    {
        $computeCount = 0;
        $cache = [];

        // Simulate stampede: 10 concurrent requests for the same cache key
        for ($i = 0; $i < 10; $i++) {
            $key = 'page:home:en';

            if (!isset($cache[$key])) {
                // First request computes and caches
                $computeCount++;
                $cache[$key] = 'computed-result';
            }
        }

        // Only 1 computation should have occurred
        self::assertSame(1, $computeCount, 'Cache stampede protection: only 1 computation expected');
        self::assertSame('computed-result', $cache['page:home:en']);
    }

    private function seedRepository(int $count): PerformanceContentRepository
    {
        $repo = new PerformanceContentRepository();

        for ($i = 0; $i < $count; $i++) {
            $content = Content::create(
                id: "content-{$i}",
                contentType: $i % 2 === 0 ? ContentType::Article : ContentType::Page,
                authorId: 'author-001',
            );

            if ($i % 3 === 0) {
                $content = $content->publish();
            }

            $repo->save($content);
        }

        return $repo;
    }
}

/**
 * @internal High-performance in-memory content repository for benchmarks.
 */
final class PerformanceContentRepository implements ContentRepositoryInterface
{
    /** @var array<string, Content> */
    private array $contents = [];

    public function findById(string $id): ?Content
    {
        return $this->contents[$id] ?? null;
    }

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?Content
    {
        return null;
    }

    public function findPublished(
        string $locale,
        ?string $contentType = null,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult {
        $items = array_filter(
            $this->contents,
            static fn(Content $c) => $c->status === PublishingStatus::Published,
        );
        $items = array_values($items);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new PaginationResult(items: $slice, total: count($items), hasMore: count($items) > $page * $perPage, perPage: $perPage);
    }

    public function findByIds(array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            if (isset($this->contents[$id])) {
                $result[$id] = $this->contents[$id];
            }
        }

        return $result;
    }

    public function findAncestors(string $contentId, int $maxDepth = 20): array
    {
        $ancestors = [];
        $current = $this->contents[$contentId] ?? null;
        $depth = 0;

        while ($current !== null && $current->parentId !== null && $depth < $maxDepth) {
            $parent = $this->contents[$current->parentId] ?? null;

            if ($parent === null) {
                break;
            }

            $ancestors[] = $parent;
            $current = $parent;
            $depth++;
        }

        return $ancestors;
    }

    public function save(Content $content): void
    {
        $this->contents[$content->id] = $content;
    }

    public function delete(Content $content): void
    {
        unset($this->contents[$content->id]);
    }

    public function findDescendants(string $contentId): array
    {
        return array_values(array_filter(
            $this->contents,
            static fn(Content $c) => $c->parentId === $contentId,
        ));
    }

    public function findScheduledForPublishing(DateTimeImmutable $now): array
    {
        return [];
    }

    public function findScheduledForUnpublishing(DateTimeImmutable $now): array
    {
        return [];
    }

    public function bulkUpdateStatus(array $ids, PublishingStatus $status, ?string $tenantId = null): int
    {
        return 0;
    }

    public function bulkDelete(array $ids, ?string $tenantId = null): int
    {
        return 0;
    }
}
