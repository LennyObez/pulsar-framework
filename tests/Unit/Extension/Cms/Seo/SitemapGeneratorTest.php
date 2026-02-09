<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Seo;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Seo\SitemapGenerator;

#[CoversClass(SitemapGenerator::class)]
final class SitemapGeneratorTest extends TestCase
{
    private const string BASE_URL = 'https://example.com';

    // -- Index XML -------------------------------------------------------------

    #[Test]
    public function test_index_has_correct_sitemapindex_root_element(): void
    {
        $generator = $this->createGenerator(publishedCounts: ['article' => 1, 'page' => 1]);

        $xml = $generator->generateIndex(self::BASE_URL);

        self::assertStringContainsString('<sitemapindex', $xml);
        self::assertStringContainsString('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Index XML must be well-formed');
    }

    #[Test]
    public function test_index_contains_sitemap_entries_for_each_type(): void
    {
        $generator = $this->createGenerator(publishedCounts: ['article' => 1, 'page' => 1]);

        $xml = $generator->generateIndex(self::BASE_URL);

        self::assertStringContainsString('<sitemap><loc>https://example.com/sitemap-article-1.xml</loc></sitemap>', $xml);
        self::assertStringContainsString('<sitemap><loc>https://example.com/sitemap-page-1.xml</loc></sitemap>', $xml);
    }

    #[Test]
    public function test_index_strips_trailing_slash_from_base_url(): void
    {
        $generator = $this->createGenerator(publishedCounts: ['article' => 0, 'page' => 0]);

        $xml = $generator->generateIndex('https://example.com/');

        self::assertStringContainsString('https://example.com/sitemap-', $xml);
        self::assertStringNotContainsString('https://example.com//sitemap-', $xml);
    }

    // -- Segment XML ----------------------------------------------------------

    #[Test]
    public function test_segment_has_correct_urlset_with_namespaces(): void
    {
        $content = $this->createContent(ContentType::Article);
        $translations = [$this->createTranslation(locale: 'en', path: 'en/hello')];
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => $translations],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('<urlset', $xml);
        self::assertStringContainsString('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml);
        self::assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Segment XML must be well-formed');
    }

    #[Test]
    public function test_segment_url_has_loc_lastmod_changefreq_priority(): void
    {
        $content = $this->createContent(ContentType::Article);
        $translations = [$this->createTranslation(locale: 'en', path: 'en/hello')];
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => $translations],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('<loc>https://example.com/en/hello</loc>', $xml);
        self::assertStringContainsString('<lastmod>2025-06-15</lastmod>', $xml);
        self::assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
        self::assertStringContainsString('<priority>0.6</priority>', $xml);
    }

    #[Test]
    public function test_segment_hreflang_for_each_locale_variant(): void
    {
        $content = $this->createContent(ContentType::Article);
        $enTrans = $this->createTranslation(locale: 'en', path: 'en/hello');
        $frTrans = $this->createTranslation(locale: 'fr', path: 'fr/bonjour');
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => [$enTrans, $frTrans]],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('hreflang="en" href="https://example.com/en/hello"', $xml);
        self::assertStringContainsString('hreflang="fr" href="https://example.com/fr/bonjour"', $xml);
    }

    #[Test]
    public function test_segment_includes_x_default_hreflang(): void
    {
        $content = $this->createContent(ContentType::Article);
        $enTrans = $this->createTranslation(locale: 'en', path: 'en/hello');
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => [$enTrans]],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('hreflang="x-default"', $xml);
    }

    #[Test]
    public function test_empty_type_produces_valid_empty_sitemap(): void
    {
        $generator = $this->createGenerator(publishedItems: []);

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('<urlset', $xml);
        self::assertStringNotContainsString('<url>', $xml);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Empty sitemap XML must be well-formed');
    }

    #[Test]
    public function test_segment_skips_content_with_no_translations(): void
    {
        $content = $this->createContent(ContentType::Article);
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => []],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringNotContainsString('<url>', $xml);
    }

    #[Test]
    public function test_index_pagination_at_50000_urls(): void
    {
        // 60000 articles should produce 2 pages (50000 + 10000)
        $generator = $this->createGenerator(publishedCounts: ['article' => 60_000, 'page' => 0]);

        $xml = $generator->generateIndex(self::BASE_URL);

        self::assertStringContainsString('sitemap-article-1.xml', $xml);
        self::assertStringContainsString('sitemap-article-2.xml', $xml);
        self::assertStringNotContainsString('sitemap-article-3.xml', $xml);
    }

    #[Test]
    public function test_segment_xml_is_well_formed(): void
    {
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(
            locale: 'en',
            path: 'en/about',
            title: 'About & "Contact"',
        );
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => [$translation]],
        );

        $xml = $generator->generateForType('page', self::BASE_URL);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Segment XML with special chars must be well-formed');
    }

    #[Test]
    public function test_segment_uses_configured_changefreq_and_priority(): void
    {
        $content = $this->createContent(ContentType::Page);
        $translations = [$this->createTranslation(locale: 'en', path: 'en/about')];
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => $translations],
            seo: new SeoConfig(
                sitemapChangefreq: ['page' => 'daily'],
                sitemapPriority: ['page' => 0.9],
            ),
        );

        $xml = $generator->generateForType('page', self::BASE_URL);

        self::assertStringContainsString('<changefreq>daily</changefreq>', $xml);
        self::assertStringContainsString('<priority>0.9</priority>', $xml);
    }

    #[Test]
    public function test_segment_excludes_noindex_content(): void
    {
        $content = $this->createContent(ContentType::Article);
        $noindexTranslation = $this->createTranslation(locale: 'en', path: 'en/private', robots: 'noindex, follow');
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => [$noindexTranslation]],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringNotContainsString('<url>', $xml);
        self::assertStringNotContainsString('en/private', $xml);
    }

    #[Test]
    public function test_segment_excludes_noindex_case_insensitive(): void
    {
        $content = $this->createContent(ContentType::Article);
        $noindexTranslation = $this->createTranslation(locale: 'en', path: 'en/hidden', robots: 'NOINDEX, NOFOLLOW');
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => [$noindexTranslation]],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringNotContainsString('<url>', $xml);
    }

    #[Test]
    public function test_segment_includes_content_without_robots_directive(): void
    {
        $content = $this->createContent(ContentType::Article);
        $normalTranslation = $this->createTranslation(locale: 'en', path: 'en/normal');
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => [$normalTranslation]],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('<url>', $xml);
        self::assertStringContainsString('en/normal', $xml);
    }

    #[Test]
    public function test_segment_includes_content_with_index_follow_robots(): void
    {
        $content = $this->createContent(ContentType::Article);
        $indexTranslation = $this->createTranslation(locale: 'en', path: 'en/public', robots: 'index, follow');
        $generator = $this->createGenerator(
            publishedItems: [$content],
            translationsByContent: ['content-001' => [$indexTranslation]],
        );

        $xml = $generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('<url>', $xml);
        self::assertStringContainsString('en/public', $xml);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param array<string, int> $publishedCounts Total published counts by content type for index generation
     * @param list<Content> $publishedItems Content items returned for segment generation
     * @param array<string, list<ContentTranslation>> $translationsByContent contentId => translations
     */
    private function createGenerator(
        array $publishedCounts = [],
        array $publishedItems = [],
        array $translationsByContent = [],
        ?SeoConfig $seo = null,
    ): SitemapGenerator {
        $seo ??= new SeoConfig();
        $config = new CmsConfig(defaultLocale: 'en', seo: $seo);

        $contentRepo = new class ($publishedCounts, $publishedItems) implements ContentRepositoryInterface {
            /**
             * @param array<string, int> $counts
             * @param list<Content> $items
             */
            public function __construct(
                private readonly array $counts,
                private readonly array $items,
            ) {}

            public function findById(string $id): ?Content
            {
                return null;
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
                $total = $this->counts[$contentType ?? ''] ?? 0;

                return new PaginationResult(
                    items: $this->items,
                    total: $total,
                    hasMore: false,
                    perPage: $perPage,
                );
            }

            public function findByIds(array $ids): array
            {
                return [];
            }

            public function findAncestors(string $contentId, int $maxDepth = 20): array
            {
                return [];
            }

            public function save(Content $content): void {}

            public function delete(Content $content): void {}

            public function findDescendants(string $contentId): array
            {
                return [];
            }

            public function findScheduledForPublishing(DateTimeImmutable $now): array
            {
                return [];
            }

            public function findScheduledForUnpublishing(DateTimeImmutable $now): array
            {
                return [];
            }

            public function bulkUpdateStatus(array $ids, \Pulsar\Extension\Cms\Content\PublishingStatus $status, ?string $tenantId = null): int
            {
                return 0;
            }

            public function bulkDelete(array $ids, ?string $tenantId = null): int
            {
                return 0;
            }
        };

        $translationRepo = new class ($translationsByContent) implements ContentTranslationRepositoryInterface {
            /** @param array<string, list<ContentTranslation>> $map */
            public function __construct(private readonly array $map) {}

            public function findById(string $id): ?ContentTranslation
            {
                return null;
            }

            public function findByContentId(string $contentId): array
            {
                return $this->map[$contentId] ?? [];
            }

            public function findByContentAndLocale(string $contentId, string $locale): ?ContentTranslation
            {
                return null;
            }

            public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation
            {
                return null;
            }

            public function findByContentIds(array $contentIds): array
            {
                $grouped = [];

                foreach ($contentIds as $id) {
                    if (isset($this->map[$id])) {
                        $grouped[$id] = $this->map[$id];
                    }
                }

                return $grouped;
            }

            public function save(ContentTranslation $translation): void {}

            public function delete(string $id): void {}
        };

        return new SitemapGenerator($contentRepo, $translationRepo, $config);
    }

    private function createContent(ContentType $contentType): Content
    {
        $now = new DateTimeImmutable('2025-06-15T10:00:00+00:00');

        return new Content(
            id: 'content-001',
            tenantId: null,
            contentType: $contentType,
            authorId: 'author-001',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
            version: 1,
        );
    }

    private function createTranslation(
        string $locale = 'en',
        string $path = 'en/default',
        string $title = 'Default Title',
        ?string $robots = null,
    ): ContentTranslation {
        return new ContentTranslation(
            id: 'trans-' . $locale,
            contentId: 'content-001',
            locale: $locale,
            title: $title,
            slugSegment: 'default',
            path: $path,
            body: '<p>Body</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: $robots,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}
