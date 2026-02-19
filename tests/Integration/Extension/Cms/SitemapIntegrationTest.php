<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

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

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function in_array;

#[CoversClass(SitemapGenerator::class)]
final class SitemapIntegrationTest extends TestCase
{
    private InMemorySitemapContentRepository $contentRepo;
    private InMemorySitemapTranslationRepository $translationRepo;
    private SitemapGenerator $generator;

    private const string BASE_URL = 'https://example.com';

    protected function setUp(): void
    {
        $this->contentRepo = new InMemorySitemapContentRepository();
        $this->translationRepo = new InMemorySitemapTranslationRepository();

        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            seo: new SeoConfig(
                sitemapChangefreq: ['article' => 'weekly', 'page' => 'monthly'],
                sitemapPriority: ['article' => 0.6, 'page' => 0.8],
            ),
        );

        $this->generator = new SitemapGenerator(
            $this->contentRepo,
            $this->translationRepo,
            $config,
        );
    }

    #[Test]
    public function test_sitemap_includes_published_content_with_hreflang(): void
    {
        $now = new DateTimeImmutable();
        $content = $this->createContent('c-001', ContentType::Article, PublishingStatus::Published, $now);
        $this->contentRepo->add($content);

        $enTrans = $this->createTranslation('c-001', 'en', 'en/hello-world');
        $frTrans = $this->createTranslation('c-001', 'fr', 'fr/bonjour-monde');
        $this->translationRepo->add($enTrans);
        $this->translationRepo->add($frTrans);

        $xml = $this->generator->generateForType('article', self::BASE_URL);

        // Verify well-formed XML
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));

        // Verify published content appears in sitemap
        self::assertStringContainsString('<loc>https://example.com/en/hello-world</loc>', $xml);
        self::assertStringContainsString('<lastmod>' . $now->format('Y-m-d') . '</lastmod>', $xml);

        // Verify hreflang for both locales
        self::assertStringContainsString('hreflang="en"', $xml);
        self::assertStringContainsString('hreflang="fr"', $xml);
        self::assertStringContainsString('href="https://example.com/fr/bonjour-monde"', $xml);

        // Verify x-default present
        self::assertStringContainsString('hreflang="x-default"', $xml);
    }

    #[Test]
    public function test_unpublished_content_excluded_from_sitemap(): void
    {
        $now = new DateTimeImmutable();

        // Published article
        $published = $this->createContent('c-pub', ContentType::Article, PublishingStatus::Published, $now);
        $this->contentRepo->add($published);
        $this->translationRepo->add($this->createTranslation('c-pub', 'en', 'en/published'));

        // Draft article (should not appear in findPublished)
        $draft = $this->createContent('c-draft', ContentType::Article, PublishingStatus::Draft, $now);
        $this->contentRepo->add($draft);
        $this->translationRepo->add($this->createTranslation('c-draft', 'en', 'en/draft'));

        $xml = $this->generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('en/published', $xml);
        self::assertStringNotContainsString('en/draft', $xml);
    }

    #[Test]
    public function test_deleted_content_excluded_from_sitemap(): void
    {
        $now = new DateTimeImmutable();

        // Soft-deleted published content (should not appear)
        $deleted = new Content(
            id: 'c-deleted',
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'author-001',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: $now,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
            version: 1,
        );
        $this->contentRepo->add($deleted);
        $this->translationRepo->add($this->createTranslation('c-deleted', 'en', 'en/deleted'));

        // Active published content
        $active = $this->createContent('c-active', ContentType::Article, PublishingStatus::Published, $now);
        $this->contentRepo->add($active);
        $this->translationRepo->add($this->createTranslation('c-active', 'en', 'en/active'));

        $xml = $this->generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('en/active', $xml);
        self::assertStringNotContainsString('en/deleted', $xml);
    }

    #[Test]
    public function test_sitemap_index_references_all_content_types(): void
    {
        $now = new DateTimeImmutable();
        $article = $this->createContent('c-art', ContentType::Article, PublishingStatus::Published, $now);
        $page = $this->createContent('c-page', ContentType::Page, PublishingStatus::Published, $now);
        $this->contentRepo->add($article);
        $this->contentRepo->add($page);

        $xml = $this->generator->generateIndex(self::BASE_URL);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));

        self::assertStringContainsString('sitemap-article-1.xml', $xml);
        self::assertStringContainsString('sitemap-page-1.xml', $xml);
    }

    #[Test]
    public function test_multiple_published_articles_all_appear_in_sitemap(): void
    {
        $now = new DateTimeImmutable();

        for ($i = 1; $i <= 3; $i++) {
            $content = $this->createContent("c-{$i}", ContentType::Article, PublishingStatus::Published, $now);
            $this->contentRepo->add($content);
            $this->translationRepo->add($this->createTranslation("c-{$i}", 'en', "en/article-{$i}"));
        }

        $xml = $this->generator->generateForType('article', self::BASE_URL);

        self::assertStringContainsString('en/article-1', $xml);
        self::assertStringContainsString('en/article-2', $xml);
        self::assertStringContainsString('en/article-3', $xml);
    }

    // -- Helpers --------------------------------------------------------------

    private function createContent(
        string $id,
        ContentType $type,
        PublishingStatus $status,
        DateTimeImmutable $now,
    ): Content {
        return new Content(
            id: $id,
            tenantId: null,
            contentType: $type,
            authorId: 'author-001',
            status: $status,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $status === PublishingStatus::Published ? $now : null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
            version: 1,
        );
    }

    private function createTranslation(string $contentId, string $locale, string $path): ContentTranslation
    {
        return new ContentTranslation(
            id: "trans-{$contentId}-{$locale}",
            contentId: $contentId,
            locale: $locale,
            title: 'Title',
            slugSegment: 'slug',
            path: $path,
            body: '<p>Body</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: null,
            bodyPlaintext: 'Body',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}

// -- In-memory repositories for sitemap tests ---------------------------------

final class InMemorySitemapContentRepository implements ContentRepositoryInterface
{
    /** @var array<string, Content> */
    private array $contents = [];

    public function add(Content $content): void
    {
        $this->contents[$content->id] = $content;
    }

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
            static fn(Content $c) => $c->status === PublishingStatus::Published
                && $c->deletedAt === null
                && ($contentType === null || $c->contentType->value === $contentType),
        );

        $items = array_values($items);
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);

        return new PaginationResult(
            items: $pageItems,
            total: $total,
            hasMore: ($offset + $perPage) < $total,
            perPage: $perPage,
        );
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
        return [];
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

    public function bulkUpdateStatus(array $ids, PublishingStatus $status, ?string $tenantId = null): int
    {
        return 0;
    }

    public function bulkDelete(array $ids, ?string $tenantId = null): int
    {
        return 0;
    }
}

final class InMemorySitemapTranslationRepository implements ContentTranslationRepositoryInterface
{
    /** @var list<ContentTranslation> */
    private array $translations = [];

    public function add(ContentTranslation $translation): void
    {
        $this->translations[] = $translation;
    }

    public function findById(string $id): ?ContentTranslation
    {
        foreach ($this->translations as $t) {
            if ($t->id === $id) {
                return $t;
            }
        }

        return null;
    }

    public function findByContentId(string $contentId): array
    {
        return array_values(array_filter(
            $this->translations,
            static fn(ContentTranslation $t) => $t->contentId === $contentId,
        ));
    }

    public function findByContentAndLocale(string $contentId, string $locale): ?ContentTranslation
    {
        foreach ($this->translations as $t) {
            if ($t->contentId === $contentId && $t->locale === $locale) {
                return $t;
            }
        }

        return null;
    }

    public function findByContentIds(array $contentIds): array
    {
        $grouped = [];

        foreach ($this->translations as $t) {
            if (in_array($t->contentId, $contentIds, true)) {
                $grouped[$t->contentId][] = $t;
            }
        }

        return $grouped;
    }

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation
    {
        return null;
    }

    public function save(ContentTranslation $translation): void
    {
        $this->translations[] = $translation;
    }

    public function delete(string $id): void {}
}
