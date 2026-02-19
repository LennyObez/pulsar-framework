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
use Pulsar\Extension\Cms\Internal\Seo\ArticleStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Seo\SeoService;
use Pulsar\Extension\Cms\Internal\Seo\WebPageStructuredDataGenerator;
use Pulsar\Extension\Cms\Seo\JsonLdCollection;

use function array_values;

#[CoversClass(ArticleStructuredDataGenerator::class)]
#[CoversClass(WebPageStructuredDataGenerator::class)]
#[CoversClass(SeoService::class)]
#[CoversClass(JsonLdCollection::class)]
final class StructuredDataGeneratorTest extends TestCase
{
    private const string BASE_URL = 'https://example.com';

    // -- ArticleStructuredDataGenerator ---------------------------------------

    #[Test]
    public function test_article_generator_supports_article_type(): void
    {
        $generator = new ArticleStructuredDataGenerator();

        self::assertTrue($generator->supports($this->createContent(ContentType::Article)));
        self::assertFalse($generator->supports($this->createContent(ContentType::Page)));
    }

    #[Test]
    public function test_article_generates_correct_type(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('Article', $data['@type']);
    }

    #[Test]
    public function test_article_has_schema_context(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://schema.org', $data['@context']);
    }

    #[Test]
    public function test_article_headline_from_title(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(title: 'My Article Title');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('My Article Title', $data['headline']);
    }

    #[Test]
    public function test_article_headline_prefers_meta_title(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(title: 'My Title', metaTitle: 'SEO Title');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('SEO Title', $data['headline']);
    }

    #[Test]
    public function test_article_date_published_iso_8601(): void
    {
        $publishedAt = new DateTimeImmutable('2025-03-15T14:30:00+00:00');
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article, publishedAt: $publishedAt);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('2025-03-15T14:30:00+00:00', $data['datePublished']);
    }

    #[Test]
    public function test_article_date_modified_iso_8601(): void
    {
        $updatedAt = new DateTimeImmutable('2025-04-20T09:00:00+00:00');
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article, updatedAt: $updatedAt);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('2025-04-20T09:00:00+00:00', $data['dateModified']);
    }

    #[Test]
    public function test_article_in_language(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(locale: 'fr');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('fr', $data['inLanguage']);
    }

    #[Test]
    public function test_article_url_built_correctly(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(path: 'en/blog/my-post');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://example.com/en/blog/my-post', $data['url']);
    }

    #[Test]
    public function test_article_date_published_absent_when_not_published(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article, publishedAt: null);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('datePublished', $data);
    }

    #[Test]
    public function test_article_description_from_meta_description(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(metaDescription: 'Article summary');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('Article summary', $data['description']);
    }

    #[Test]
    public function test_article_description_omitted_when_absent(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(metaDescription: null);

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('description', $data);
    }

    #[Test]
    public function test_article_time_required_from_reading_time(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(readingTimeMinutes: 7);

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('PT7M', $data['timeRequired']);
    }

    #[Test]
    public function test_article_time_required_omitted_when_null(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(readingTimeMinutes: null);

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('timeRequired', $data);
    }

    // -- WebPageStructuredDataGenerator ---------------------------------------

    #[Test]
    public function test_webpage_generator_supports_page_type(): void
    {
        $generator = new WebPageStructuredDataGenerator();

        self::assertTrue($generator->supports($this->createContent(ContentType::Page)));
        self::assertFalse($generator->supports($this->createContent(ContentType::Article)));
    }

    #[Test]
    public function test_webpage_generates_correct_type(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('WebPage', $data['@type']);
    }

    #[Test]
    public function test_webpage_has_schema_context(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://schema.org', $data['@context']);
    }

    #[Test]
    public function test_webpage_name_from_title(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(title: 'About Us');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('About Us', $data['name']);
    }

    #[Test]
    public function test_webpage_name_prefers_meta_title(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(title: 'About', metaTitle: 'About Our Company');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('About Our Company', $data['name']);
    }

    #[Test]
    public function test_webpage_url_built_correctly(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(path: 'en/about');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://example.com/en/about', $data['url']);
    }

    #[Test]
    public function test_webpage_in_language(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(locale: 'de');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('de', $data['inLanguage']);
    }

    #[Test]
    public function test_webpage_date_modified_present(): void
    {
        $updatedAt = new DateTimeImmutable('2025-05-01T12:00:00+00:00');
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page, updatedAt: $updatedAt);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('2025-05-01T12:00:00+00:00', $data['dateModified']);
    }

    // -- SeoService::generateBreadcrumbJsonLd() -------------------------------

    #[Test]
    public function test_breadcrumb_generates_breadcrumb_list_type(): void
    {
        $service = $this->createSeoService();
        $breadcrumbs = [
            ['name' => 'Home', 'url' => 'https://example.com/'],
            ['name' => 'Blog', 'url' => 'https://example.com/blog'],
            ['name' => 'Article', 'url' => 'https://example.com/blog/article'],
        ];

        $collection = $service->generateBreadcrumbJsonLd($breadcrumbs);

        self::assertCount(1, $collection->items);
        self::assertSame('BreadcrumbList', $collection->items[0]['@type']);
        self::assertSame('https://schema.org', $collection->items[0]['@context']);
    }

    #[Test]
    public function test_breadcrumb_item_list_elements_with_position_name_item(): void
    {
        $service = $this->createSeoService();
        $breadcrumbs = [
            ['name' => 'Home', 'url' => 'https://example.com/'],
            ['name' => 'Blog', 'url' => 'https://example.com/blog'],
        ];

        $collection = $service->generateBreadcrumbJsonLd($breadcrumbs);

        /** @var list<array<string, mixed>> $elements */
        $elements = $collection->items[0]['itemListElement'];
        self::assertIsArray($elements);

        self::assertCount(2, $elements);

        $first = $elements[0];
        self::assertSame('ListItem', $first['@type']);
        self::assertSame(1, $first['position']);
        self::assertSame('Home', $first['name']);
        self::assertSame('https://example.com/', $first['item']);

        $second = $elements[1];
        self::assertSame('ListItem', $second['@type']);
        self::assertSame(2, $second['position']);
        self::assertSame('Blog', $second['name']);
        self::assertSame('https://example.com/blog', $second['item']);
    }

    #[Test]
    public function test_breadcrumb_empty_input_returns_empty_collection(): void
    {
        $service = $this->createSeoService();

        $collection = $service->generateBreadcrumbJsonLd([]);

        self::assertSame([], $collection->items);
    }

    // -- SeoService::generateStructuredData() ---------------------------------

    #[Test]
    public function test_generate_structured_data_uses_matching_generators(): void
    {
        $articleGenerator = new ArticleStructuredDataGenerator();
        $webpageGenerator = new WebPageStructuredDataGenerator();
        $service = $this->createSeoService(generators: [$articleGenerator, $webpageGenerator]);

        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation();

        $collection = $service->generateStructuredData($content, $translation, self::BASE_URL);

        self::assertCount(1, $collection->items);
        self::assertSame('Article', $collection->items[0]['@type']);
    }

    #[Test]
    public function test_generate_structured_data_returns_empty_when_disabled(): void
    {
        $service = $this->createSeoService(
            seo: new SeoConfig(enableStructuredData: false),
            generators: [new ArticleStructuredDataGenerator()],
        );

        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation();

        $collection = $service->generateStructuredData($content, $translation, self::BASE_URL);

        self::assertSame([], $collection->items);
    }

    #[Test]
    public function test_generate_structured_data_includes_page_overrides(): void
    {
        $service = $this->createSeoService(generators: []);
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(structuredDataOverrides: [
            '@type' => 'Product',
            'name' => 'Custom Product',
        ]);

        $collection = $service->generateStructuredData($content, $translation, self::BASE_URL);

        self::assertCount(1, $collection->items);
        self::assertSame('Product', $collection->items[0]['@type']);
    }

    // -- JsonLdCollection::toScript() -----------------------------------------

    #[Test]
    public function test_to_script_produces_valid_script_tag(): void
    {
        $collection = new JsonLdCollection([
            ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'Test'],
        ]);

        $script = $collection->toScript();

        self::assertStringStartsWith('<script type="application/ld+json">', $script);
        self::assertStringEndsWith('</script>', $script);
        self::assertStringContainsString('"@context":"https://schema.org"', $script);
        self::assertStringContainsString('"@type":"WebPage"', $script);
    }

    #[Test]
    public function test_to_script_empty_collection_returns_empty_string(): void
    {
        $collection = new JsonLdCollection();

        self::assertSame('', $collection->toScript());
    }

    #[Test]
    public function test_to_script_multiple_items_uses_graph(): void
    {
        $collection = new JsonLdCollection([
            ['@type' => 'WebPage', 'name' => 'Page'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => []],
        ]);

        $script = $collection->toScript();

        self::assertStringContainsString('"@graph"', $script);
    }

    #[Test]
    public function test_to_script_single_item_no_graph_wrapper(): void
    {
        $collection = new JsonLdCollection([
            ['@type' => 'WebPage', 'name' => 'Page'],
        ]);

        $script = $collection->toScript();

        self::assertStringNotContainsString('"@graph"', $script);
    }

    // -- Helpers --------------------------------------------------------------

    private function createContent(
        ContentType $contentType,
        ?DateTimeImmutable $publishedAt = new DateTimeImmutable('2025-06-15T10:00:00+00:00'),
        ?DateTimeImmutable $updatedAt = null,
    ): Content {
        $now = new DateTimeImmutable('2025-06-15T10:00:00+00:00');

        return new Content(
            id: 'content-001',
            tenantId: null,
            contentType: $contentType,
            authorId: 'author-001',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $publishedAt,
            createdAt: $now,
            updatedAt: $updatedAt ?? $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );
    }

    /** @param array<string, mixed>|null $structuredDataOverrides */
    private function createTranslation(
        string $title = 'Default Title',
        string $path = 'en/default',
        string $locale = 'en',
        ?string $metaTitle = null,
        ?string $metaDescription = null,
        ?int $readingTimeMinutes = 5,
        ?array $structuredDataOverrides = null,
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
            robots: null,
            structuredDataOverrides: $structuredDataOverrides,
            readingTimeMinutes: $readingTimeMinutes,
            bodyPlaintext: 'Body text',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }

    /** @param list<\Pulsar\Extension\Cms\Seo\StructuredDataGeneratorInterface> $generators */
    private function createSeoService(
        ?SeoConfig $seo = null,
        array $generators = [],
    ): SeoService {
        $seo ??= new SeoConfig();
        $config = new CmsConfig(seo: $seo);

        $translationRepo = new class implements ContentTranslationRepositoryInterface {
            public function findById(string $id): ?ContentTranslation
            {
                return null;
            }

            public function findByContentId(string $contentId): array
            {
                return [];
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
                return [];
            }

            public function save(ContentTranslation $translation): void {}

            public function delete(string $id): void {}
        };

        return new SeoService($config, $translationRepo, array_values($generators));
    }
}
