<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Seo;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
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
use Pulsar\Extension\Cms\Internal\Seo\OrganizationStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Seo\ProductStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Seo\SeoService;
use Pulsar\Extension\Cms\Internal\Seo\WebPageStructuredDataGenerator;
use Pulsar\Extension\Cms\Seo\JsonLdCollection;

use function array_values;

#[CoversClass(ArticleStructuredDataGenerator::class)]
#[CoversClass(WebPageStructuredDataGenerator::class)]
#[CoversClass(ProductStructuredDataGenerator::class)]
#[CoversClass(OrganizationStructuredDataGenerator::class)]
#[CoversClass(SeoService::class)]
#[CoversClass(JsonLdCollection::class)]
final class StructuredDataGeneratorTest extends TestCase
{
    private const string BASE_URL = 'https://example.com';

    // -- ArticleStructuredDataGenerator ---------------------------------------

    #[Test]
    public function articleGeneratorSupportsArticleType(): void
    {
        $generator = new ArticleStructuredDataGenerator();

        self::assertTrue($generator->supports($this->createContent(ContentType::Article)));
        self::assertFalse($generator->supports($this->createContent(ContentType::Page)));
    }

    #[Test]
    public function articleGeneratesCorrectType(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('Article', $data['@type']);
    }

    #[Test]
    public function articleHasSchemaContext(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://schema.org', $data['@context']);
    }

    #[Test]
    public function articleHeadlineFromTitle(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(title: 'My Article Title');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('My Article Title', $data['headline']);
    }

    #[Test]
    public function articleHeadlinePrefersMetaTitle(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(title: 'My Title', metaTitle: 'SEO Title');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('SEO Title', $data['headline']);
    }

    #[Test]
    public function articleDatePublishedIso8601(): void
    {
        $publishedAt = new DateTimeImmutable('2025-03-15T14:30:00+00:00');
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article, publishedAt: $publishedAt);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('2025-03-15T14:30:00+00:00', $data['datePublished']);
    }

    #[Test]
    public function articleDateModifiedIso8601(): void
    {
        $updatedAt = new DateTimeImmutable('2025-04-20T09:00:00+00:00');
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article, updatedAt: $updatedAt);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('2025-04-20T09:00:00+00:00', $data['dateModified']);
    }

    #[Test]
    public function articleInLanguage(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(locale: 'fr');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('fr', $data['inLanguage']);
    }

    #[Test]
    public function articleUrlBuiltCorrectly(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(path: 'en/blog/my-post');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://example.com/en/blog/my-post', $data['url']);
    }

    #[Test]
    public function articleDatePublishedAbsentWhenNotPublished(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article, publishedAt: null);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('datePublished', $data);
    }

    #[Test]
    public function articleDescriptionFromMetaDescription(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(metaDescription: 'Article summary');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('Article summary', $data['description']);
    }

    #[Test]
    public function articleDescriptionOmittedWhenAbsent(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(metaDescription: null);

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('description', $data);
    }

    #[Test]
    public function articleTimeRequiredFromReadingTime(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(readingTimeMinutes: 7);

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('PT7M', $data['timeRequired']);
    }

    #[Test]
    public function articleTimeRequiredOmittedWhenNull(): void
    {
        $generator = new ArticleStructuredDataGenerator();
        $content = $this->createContent(ContentType::Article);
        $translation = $this->createTranslation(readingTimeMinutes: null);

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('timeRequired', $data);
    }

    // -- WebPageStructuredDataGenerator ---------------------------------------

    #[Test]
    public function webpageGeneratorSupportsPageType(): void
    {
        $generator = new WebPageStructuredDataGenerator();

        self::assertTrue($generator->supports($this->createContent(ContentType::Page)));
        self::assertFalse($generator->supports($this->createContent(ContentType::Article)));
    }

    #[Test]
    public function webpageGeneratesCorrectType(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('WebPage', $data['@type']);
    }

    #[Test]
    public function webpageHasSchemaContext(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://schema.org', $data['@context']);
    }

    #[Test]
    public function webpageNameFromTitle(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(title: 'About Us');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('About Us', $data['name']);
    }

    #[Test]
    public function webpageNamePrefersMetaTitle(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(title: 'About', metaTitle: 'About Our Company');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('About Our Company', $data['name']);
    }

    #[Test]
    public function webpageUrlBuiltCorrectly(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(path: 'en/about');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://example.com/en/about', $data['url']);
    }

    #[Test]
    public function webpageInLanguage(): void
    {
        $generator = new WebPageStructuredDataGenerator();
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(locale: 'de');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('de', $data['inLanguage']);
    }

    #[Test]
    public function webpageDateModifiedPresent(): void
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
    public function breadcrumbGeneratesBreadcrumbListType(): void
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
    public function breadcrumbItemListElementsWithPositionNameItem(): void
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
    public function breadcrumbEmptyInputReturnsEmptyCollection(): void
    {
        $service = $this->createSeoService();

        $collection = $service->generateBreadcrumbJsonLd([]);

        self::assertSame([], $collection->items);
    }

    // -- SeoService::generateStructuredData() ---------------------------------

    #[Test]
    public function generateStructuredDataUsesMatchingGenerators(): void
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
    public function generateStructuredDataReturnsEmptyWhenDisabled(): void
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
    public function generateStructuredDataIncludesPageOverrides(): void
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
    public function toScriptProducesValidScriptTag(): void
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
    public function toScriptEmptyCollectionReturnsEmptyString(): void
    {
        $collection = new JsonLdCollection();

        self::assertSame('', $collection->toScript());
    }

    #[Test]
    public function toScriptMultipleItemsUsesGraph(): void
    {
        $collection = new JsonLdCollection([
            ['@type' => 'WebPage', 'name' => 'Page'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => []],
        ]);

        $script = $collection->toScript();

        self::assertStringContainsString('"@graph"', $script);
    }

    #[Test]
    public function toScriptSingleItemNoGraphWrapper(): void
    {
        $collection = new JsonLdCollection([
            ['@type' => 'WebPage', 'name' => 'Page'],
        ]);

        $script = $collection->toScript();

        self::assertStringNotContainsString('"@graph"', $script);
    }

    // -- ProductStructuredDataGenerator ----------------------------------------

    #[Test]
    public function productGeneratorSupportsContentWithLinkedProduct(): void
    {
        $product = $this->createProduct();
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        self::assertTrue($generator->supports($this->createContent(ContentType::Page)));
    }

    #[Test]
    public function productGeneratorNotSupportsContentWithoutLinkedProduct(): void
    {
        $productRepo = $this->createProductRepository(null);
        $generator = new ProductStructuredDataGenerator($productRepo);

        self::assertFalse($generator->supports($this->createContent(ContentType::Page)));
    }

    #[Test]
    public function productGeneratesCorrectType(): void
    {
        $product = $this->createProduct();
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('Product', $data['@type']);
        self::assertSame('https://schema.org', $data['@context']);
    }

    #[Test]
    public function productHasSku(): void
    {
        $product = $this->createProduct(sku: 'WIDGET-001');
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('WIDGET-001', $data['sku']);
    }

    #[Test]
    public function productHasOffersWithPrice(): void
    {
        $product = $this->createProduct(priceAmount: 2999, priceCurrency: 'USD');
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertIsArray($data['offers']);
        self::assertSame('Offer', $data['offers']['@type']);
        self::assertSame('29.99', $data['offers']['price']);
        self::assertSame('USD', $data['offers']['priceCurrency']);
    }

    #[Test]
    public function productInStockAvailability(): void
    {
        $product = $this->createProduct(stockQuantity: 10);
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertIsArray($data['offers']);
        self::assertSame('https://schema.org/InStock', $data['offers']['availability']);
    }

    #[Test]
    public function productOutOfStockAvailability(): void
    {
        $product = $this->createProduct(stockQuantity: 0);
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertIsArray($data['offers']);
        self::assertSame('https://schema.org/OutOfStock', $data['offers']['availability']);
    }

    #[Test]
    public function productNameFromTitle(): void
    {
        $product = $this->createProduct();
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(title: 'Widget Pro');
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('Widget Pro', $data['name']);
    }

    #[Test]
    public function productNamePrefersMetaTitle(): void
    {
        $product = $this->createProduct();
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(title: 'Widget', metaTitle: 'Widget Pro - Best Price');
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('Widget Pro - Best Price', $data['name']);
    }

    #[Test]
    public function productDescriptionFromMetaDescription(): void
    {
        $product = $this->createProduct();
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(metaDescription: 'The best widget');
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('The best widget', $data['description']);
    }

    #[Test]
    public function productDescriptionOmittedWhenAbsent(): void
    {
        $product = $this->createProduct();
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(metaDescription: null);
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('description', $data);
    }

    #[Test]
    public function productUrlBuiltCorrectly(): void
    {
        $product = $this->createProduct();
        $productRepo = $this->createProductRepository($product);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(path: 'en/products/widget');
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://example.com/en/products/widget', $data['url']);
    }

    #[Test]
    public function productReturnsEmptyWhenNoLinkedProduct(): void
    {
        $productRepo = $this->createProductRepository(null);
        $generator = new ProductStructuredDataGenerator($productRepo);

        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();
        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame([], $data);
    }

    // -- OrganizationStructuredDataGenerator -----------------------------------

    #[Test]
    public function organizationGeneratesCorrectType(): void
    {
        $generator = new OrganizationStructuredDataGenerator(new CmsConfig());
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(title: 'Homepage');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('Organization', $data['@type']);
        self::assertSame('https://schema.org', $data['@context']);
    }

    #[Test]
    public function organizationUsesTitleSuffixAsName(): void
    {
        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: '| Acme Corp'));
        $generator = new OrganizationStructuredDataGenerator($config);
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('| Acme Corp', $data['name']);
    }

    #[Test]
    public function organizationFallsBackToPageTitleWhenNoSuffix(): void
    {
        $config = new CmsConfig(seo: new SeoConfig(titleSuffix: ''));
        $generator = new OrganizationStructuredDataGenerator($config);
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(title: 'My Company');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('My Company', $data['name']);
    }

    #[Test]
    public function organizationUsesBaseUrlAsUrl(): void
    {
        $generator = new OrganizationStructuredDataGenerator(new CmsConfig());
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation();

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('https://example.com', $data['url']);
    }

    #[Test]
    public function organizationDescriptionFromMetaDescription(): void
    {
        $generator = new OrganizationStructuredDataGenerator(new CmsConfig());
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(metaDescription: 'We build great things.');

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertSame('We build great things.', $data['description']);
    }

    #[Test]
    public function organizationDescriptionOmittedWhenAbsent(): void
    {
        $generator = new OrganizationStructuredDataGenerator(new CmsConfig());
        $content = $this->createContent(ContentType::Page);
        $translation = $this->createTranslation(metaDescription: null);

        $data = $generator->generate($content, $translation, self::BASE_URL);

        self::assertArrayNotHasKey('description', $data);
    }

    #[Test]
    public function organizationSupportsRootPage(): void
    {
        $generator = new OrganizationStructuredDataGenerator(new CmsConfig());
        $content = $this->createContent(ContentType::Page);

        self::assertTrue($generator->supports($content));
    }

    #[Test]
    public function organizationNotSupportsChildPage(): void
    {
        $generator = new OrganizationStructuredDataGenerator(new CmsConfig());
        $now = new DateTimeImmutable('2025-06-15T10:00:00+00:00');

        $childContent = new Content(
            id: 'content-child',
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
            parentId: 'content-001',
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
            version: 1,
        );

        self::assertFalse($generator->supports($childContent));
    }

    // -- Helpers --------------------------------------------------------------

    private function createProduct(
        string $sku = 'SKU-001',
        int $priceAmount = 1999,
        string $priceCurrency = 'EUR',
        int $stockQuantity = 5,
    ): Product {
        $now = new DateTimeImmutable('2025-06-15T10:00:00+00:00');

        return new Product(
            id: 'product-001',
            tenantId: null,
            sku: $sku,
            status: ProductStatus::Active,
            priceAmount: $priceAmount,
            priceCurrency: $priceCurrency,
            taxCategory: null,
            stockQuantity: $stockQuantity,
            digital: false,
            contentId: 'content-001',
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function createProductRepository(?Product $product): ProductRepositoryInterface
    {
        return new class ($product) implements ProductRepositoryInterface {
            public function __construct(private readonly ?Product $product) {}

            public function findById(string $id): ?Product
            {
                return $this->product;
            }

            public function findByIds(array $ids): array
            {
                return $this->product !== null ? [$this->product->id => $this->product] : [];
            }

            public function findBySku(string $sku, ?string $tenantId = null): ?Product
            {
                return $this->product;
            }

            public function findByContentId(string $contentId): ?Product
            {
                return $this->product;
            }

            public function listProducts(array $filters, int $page, int $perPage): array
            {
                return $this->product !== null ? [$this->product] : [];
            }

            public function save(Product $product): void {}

            public function reserveStock(string $productId, int $quantity): bool
            {
                return true;
            }

            public function restoreStock(string $productId, int $quantity): void {}
        };
    }

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
            version: 1,
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
