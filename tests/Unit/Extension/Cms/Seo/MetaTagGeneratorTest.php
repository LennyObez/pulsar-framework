<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Seo;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Config\SeoConfig;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Internal\Seo\SeoService;
use Pulsar\Extension\Cms\Seo\MetaTagCollection;

use function array_values;

#[CoversClass(SeoService::class)]
#[CoversClass(MetaTagCollection::class)]
final class MetaTagGeneratorTest extends TestCase
{
    private const string BASE_URL = 'https://example.com';

    // -- Title ----------------------------------------------------------------

    #[Test]
    public function test_title_with_suffix_appended_correctly(): void
    {
        $service = $this->createService(seo: new SeoConfig(titleSuffix: '| My Site'));
        $content = $this->createArticle();
        $translation = $this->createTranslation(title: 'Hello World');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('Hello World | My Site', $meta->title);
    }

    #[Test]
    public function test_custom_meta_title_overrides_title_and_suffix(): void
    {
        $service = $this->createService(seo: new SeoConfig(titleSuffix: '| My Site'));
        $content = $this->createArticle();
        $translation = $this->createTranslation(title: 'Hello World', metaTitle: 'Custom SEO Title');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('Custom SEO Title | My Site', $meta->title);
    }

    #[Test]
    public function test_title_without_suffix_when_suffix_is_empty(): void
    {
        $service = $this->createService(seo: new SeoConfig(titleSuffix: ''));
        $content = $this->createArticle();
        $translation = $this->createTranslation(title: 'Plain Title');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('Plain Title', $meta->title);
    }

    // -- Description ----------------------------------------------------------

    #[Test]
    public function test_description_from_meta_description(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(metaDescription: 'A short description.');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('A short description.', $meta->description);
    }

    #[Test]
    public function test_description_null_when_meta_description_absent(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(metaDescription: null);

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertNull($meta->description);
    }

    // -- Canonical URL --------------------------------------------------------

    #[Test]
    public function test_canonical_url_built_from_base_url_and_path(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(path: 'en/blog/my-article');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('https://example.com/en/blog/my-article', $meta->canonical);
    }

    #[Test]
    public function test_canonical_url_strips_trailing_slash_from_base(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(path: 'page');

        $meta = $service->generateMetaTags($content, $translation, 'https://example.com/');

        self::assertSame('https://example.com/page', $meta->canonical);
    }

    // -- Robots ---------------------------------------------------------------

    #[Test]
    public function test_robots_from_per_page_override(): void
    {
        $service = $this->createService(seo: new SeoConfig(defaultRobots: 'index, follow'));
        $content = $this->createArticle();
        $translation = $this->createTranslation(robots: 'noindex, nofollow');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('noindex, nofollow', $meta->robots);
    }

    #[Test]
    public function test_robots_falls_back_to_config_default(): void
    {
        $service = $this->createService(seo: new SeoConfig(defaultRobots: 'index, follow'));
        $content = $this->createArticle();
        $translation = $this->createTranslation(robots: null);

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('index, follow', $meta->robots);
    }

    // -- OG tags --------------------------------------------------------------

    #[Test]
    public function test_og_type_article_for_articles(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation();

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('article', $meta->ogTags['og:type']);
    }

    #[Test]
    public function test_og_type_website_for_pages(): void
    {
        $service = $this->createService();
        $content = $this->createPage();
        $translation = $this->createTranslation();

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('website', $meta->ogTags['og:type']);
    }

    #[Test]
    public function test_og_title_matches_computed_title(): void
    {
        $service = $this->createService(seo: new SeoConfig(titleSuffix: '| Blog'));
        $content = $this->createArticle();
        $translation = $this->createTranslation(title: 'My Post');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('My Post | Blog', $meta->ogTags['og:title']);
    }

    #[Test]
    public function test_og_url_matches_canonical(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(path: 'en/article');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame($meta->canonical, $meta->ogTags['og:url']);
    }

    #[Test]
    public function test_og_description_present_when_meta_description_set(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(metaDescription: 'SEO description');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('SEO description', $meta->ogTags['og:description']);
    }

    #[Test]
    public function test_og_description_omitted_when_meta_description_absent(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(metaDescription: null);

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('og:description', $meta->ogTags);
    }

    // -- Twitter Cards --------------------------------------------------------

    #[Test]
    public function test_twitter_card_fields_present(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(title: 'Tweet This', metaDescription: 'A description');

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertSame('summary', $meta->twitterCards['twitter:card']);
        self::assertSame($meta->title, $meta->twitterCards['twitter:title']);
        self::assertSame('A description', $meta->twitterCards['twitter:description']);
    }

    #[Test]
    public function test_twitter_description_omitted_when_description_absent(): void
    {
        $service = $this->createService();
        $content = $this->createArticle();
        $translation = $this->createTranslation(metaDescription: null);

        $meta = $service->generateMetaTags($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('twitter:description', $meta->twitterCards);
    }

    // -- Hreflang links -------------------------------------------------------

    #[Test]
    public function test_hreflang_links_one_per_locale_variant(): void
    {
        $enTranslation = $this->createTranslation(locale: 'en', path: 'en/article');
        $frTranslation = $this->createTranslation(locale: 'fr', path: 'fr/article');

        $service = $this->createService(
            translations: [$enTranslation, $frTranslation],
        );
        $content = $this->createArticle();

        $meta = $service->generateMetaTags($content, $enTranslation, self::BASE_URL);

        self::assertArrayHasKey('en', $meta->hreflangLinks);
        self::assertArrayHasKey('fr', $meta->hreflangLinks);
        self::assertSame('https://example.com/en/article', $meta->hreflangLinks['en']);
        self::assertSame('https://example.com/fr/article', $meta->hreflangLinks['fr']);
    }

    #[Test]
    public function test_hreflang_includes_x_default(): void
    {
        $enTranslation = $this->createTranslation(locale: 'en', path: 'en/article');
        $frTranslation = $this->createTranslation(locale: 'fr', path: 'fr/article');

        $service = $this->createService(
            translations: [$enTranslation, $frTranslation],
            defaultLocale: 'en',
        );
        $content = $this->createArticle();

        $meta = $service->generateMetaTags($content, $enTranslation, self::BASE_URL);

        self::assertArrayHasKey('x-default', $meta->hreflangLinks);
        self::assertSame('https://example.com/en/article', $meta->hreflangLinks['x-default']);
    }

    // -- MetaTagCollection::toHtml() ------------------------------------------

    #[Test]
    public function test_to_html_produces_valid_meta_tags(): void
    {
        $collection = new MetaTagCollection(
            title: 'Test Page',
            description: 'A test description',
            canonical: 'https://example.com/test',
            robots: 'index, follow',
            ogTags: ['og:title' => 'Test Page', 'og:type' => 'website'],
            twitterCards: ['twitter:card' => 'summary'],
            hreflangLinks: ['en' => 'https://example.com/test', 'fr' => 'https://example.com/fr/test'],
        );

        $html = $collection->toHtml();

        self::assertStringContainsString('<title>Test Page</title>', $html);
        self::assertStringContainsString('<meta name="description" content="A test description">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.com/test">', $html);
        self::assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        self::assertStringContainsString('<meta property="og:title" content="Test Page">', $html);
        self::assertStringContainsString('<meta property="og:type" content="website">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://example.com/test">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="fr" href="https://example.com/fr/test">', $html);
    }

    #[Test]
    public function test_to_html_escapes_special_characters(): void
    {
        $collection = new MetaTagCollection(
            title: 'Title with "quotes" & <entities>',
        );

        $html = $collection->toHtml();

        self::assertStringContainsString('&quot;', $html);
        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('&lt;', $html);
    }

    #[Test]
    public function test_to_html_empty_collection_produces_empty_string(): void
    {
        $collection = new MetaTagCollection();

        self::assertSame('', $collection->toHtml());
    }

    // -- Helpers --------------------------------------------------------------

    /** @param list<ContentTranslation>|null $translations */
    private function createService(
        ?SeoConfig $seo = null,
        ?array $translations = null,
        string $defaultLocale = 'en',
    ): SeoService {
        $seo ??= new SeoConfig();
        $config = new CmsConfig(
            defaultLocale: $defaultLocale,
            supportedLocales: ['en', 'fr'],
            seo: $seo,
        );

        $translationRepo = new class (array_values($translations ?? [])) implements ContentTranslationRepositoryInterface {
            /** @param list<ContentTranslation> $translations */
            public function __construct(private readonly array $translations) {}

            public function findById(string $id): ?ContentTranslation
            {
                return null;
            }

            public function findByContentId(string $contentId): array
            {
                return $this->translations;
            }

            public function findByContentAndLocale(string $contentId, string $locale): ?ContentTranslation
            {
                return null;
            }

            public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation
            {
                return null;
            }

            public function save(ContentTranslation $translation): void {}

            public function delete(string $id): void {}
        };

        return new SeoService($config, $translationRepo);
    }

    private function createArticle(?DateTimeImmutable $publishedAt = null): Content
    {
        $now = new DateTimeImmutable('2025-06-15T10:00:00+00:00');

        return new Content(
            id: 'content-001',
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'author-001',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $publishedAt ?? $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );
    }

    private function createPage(): Content
    {
        $now = new DateTimeImmutable('2025-06-15T10:00:00+00:00');

        return new Content(
            id: 'content-002',
            tenantId: null,
            contentType: ContentType::Page,
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
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
        );
    }

    private function createTranslation(
        string $title = 'Default Title',
        string $path = 'en/default',
        string $locale = 'en',
        ?string $metaTitle = null,
        ?string $metaDescription = null,
        ?string $robots = null,
    ): ContentTranslation {
        return new ContentTranslation(
            id: 'trans-001',
            contentId: 'content-001',
            locale: $locale,
            title: $title,
            slugSegment: 'default',
            path: $path,
            body: '<p>Body text</p>',
            excerpt: null,
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            ogImageId: null,
            robots: $robots,
            structuredDataOverrides: null,
            readingTimeMinutes: 5,
            bodyPlaintext: 'Body text',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}
