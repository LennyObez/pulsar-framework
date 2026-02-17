<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Tools;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;
use Pulsar\Extension\Cms\Internal\Tools\SiteDefinitionParser;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;
use Pulsar\Extension\Cms\Tools\ImportConfig;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

#[CoversClass(SiteDefinitionParser::class)]
final class SiteDefinitionParserTest extends TestCase
{
    private ContentRepositoryInterface&Stub $contentRepo;
    private TaxonomyServiceInterface&Stub $taxonomyService;
    private TaxonomyRepositoryInterface&Stub $taxonomyRepo;
    private MenuRepositoryInterface&Stub $menuRepo;
    private MediaServiceInterface&Stub $mediaService;
    private SettingsServiceInterface&Stub $settingsService;
    private RedirectRepositoryInterface&Stub $redirectRepo;
    private SitemapGeneratorInterface&Stub $sitemapGenerator;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->taxonomyService = $this->createStub(TaxonomyServiceInterface::class);
        $this->taxonomyRepo = $this->createStub(TaxonomyRepositoryInterface::class);
        $this->menuRepo = $this->createStub(MenuRepositoryInterface::class);
        $this->mediaService = $this->createStub(MediaServiceInterface::class);
        $this->settingsService = $this->createStub(SettingsServiceInterface::class);
        $this->redirectRepo = $this->createStub(RedirectRepositoryInterface::class);
        $this->sitemapGenerator = $this->createStub(SitemapGeneratorInterface::class);
    }

    private function createParser(?ImportConfig $config = null): SiteDefinitionParser
    {
        $securityConfig = new CmsSecurityConfig();
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        return new SiteDefinitionParser(
            $this->contentRepo,
            $this->taxonomyService,
            $this->taxonomyRepo,
            $this->menuRepo,
            $this->mediaService,
            $this->settingsService,
            $this->redirectRepo,
            $this->sitemapGenerator,
            $httpClient,
            $config ?? new ImportConfig(),
            null,
        );
    }

    //: Idempotent Import (import_id) Tests --

    #[Test]
    public function taxonomyWithImportIdIsCreatedOnFirstImport(): void
    {
        $this->taxonomyRepo->method('findByImportId')->willReturn(null);

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [
                ['slug' => 'categories', 'name' => 'Categories', 'import_id' => 'tax:categories'],
            ],
            content: [],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(1, $result->created['taxonomies']);
        self::assertSame(0, $result->updated['taxonomies']);
    }

    #[Test]
    public function taxonomyWithExistingImportIdIsUpdated(): void
    {
        $existingTaxonomy = new Taxonomy(
            id: 'existing-tax-id',
            tenantId: null,
            slug: 'categories',
            hierarchical: false,
            createdAt: new DateTimeImmutable(),
            importId: 'tax:categories',
        );
        $this->taxonomyRepo->method('findByImportId')->willReturn($existingTaxonomy);

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [
                ['slug' => 'categories', 'name' => 'Categories Updated', 'import_id' => 'tax:categories'],
            ],
            content: [],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(0, $result->created['taxonomies']);
        self::assertSame(1, $result->updated['taxonomies']);
    }

    #[Test]
    public function taxonomyWithoutImportIdIsAlwaysCreated(): void
    {
        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [
                ['slug' => 'categories', 'name' => 'Categories'],
            ],
            content: [],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(1, $result->created['taxonomies']);
        self::assertSame(0, $result->updated['taxonomies']);
    }

    #[Test]
    public function contentWithImportIdIsCreatedOnFirstImport(): void
    {
        $this->contentRepo->method('findByImportId')->willReturn(null);

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [
                [
                    'slug' => 'about',
                    'title' => 'About Us',
                    'import_id' => 'page:about',
                    'body' => '<p>About us</p>',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(1, $result->created['content']);
        self::assertSame(0, $result->updated['content']);
    }

    #[Test]
    public function contentWithExistingImportIdIsUpdated(): void
    {
        $existingContent = Content::create(
            id: 'existing-content-id',
            contentType: ContentType::Page,
            authorId: 'system',
        );
        $this->contentRepo->method('findByImportId')->willReturn($existingContent);

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [
                [
                    'slug' => 'about',
                    'title' => 'About Us Updated',
                    'import_id' => 'page:about',
                    'body' => '<p>Updated</p>',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(0, $result->created['content']);
        self::assertSame(1, $result->updated['content']);
    }

    #[Test]
    public function menuWithImportIdIsCreatedOnFirstImport(): void
    {
        $this->menuRepo->method('findByImportId')->willReturn(null);

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [],
            menus: [
                [
                    'location' => 'primary',
                    'name' => 'Main Nav',
                    'import_id' => 'menu:primary',
                    'items' => [],
                ],
            ],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(1, $result->created['menus']);
        self::assertSame(0, $result->updated['menus']);
    }

    #[Test]
    public function menuWithExistingImportIdIsUpdated(): void
    {
        $existingMenu = new Menu(
            id: 'existing-menu-id',
            tenantId: null,
            location: 'primary',
            createdAt: new DateTimeImmutable(),
            importId: 'menu:primary',
        );
        $this->menuRepo->method('findByImportId')->willReturn($existingMenu);

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [],
            menus: [
                [
                    'location' => 'primary',
                    'name' => 'Main Nav Updated',
                    'import_id' => 'menu:primary',
                    'items' => [],
                ],
            ],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(0, $result->created['menus']);
        self::assertSame(1, $result->updated['menus']);
    }

    //: Template Field Tests --

    #[Test]
    public function contentImportPreservesTemplateField(): void
    {
        $this->contentRepo->method('findByImportId')->willReturn(null);

        $capturedContent = null;
        $this->contentRepo->method('save')->willReturnCallback(
            static function (Content $content) use (&$capturedContent): void {
                $capturedContent = $content;
            },
        );

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [
                [
                    'slug' => 'pricing',
                    'title' => 'Pricing',
                    'import_id' => 'page:pricing',
                    'template' => 'pages/pricing-with-calculator',
                    'body' => '<p>Pricing content</p>',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $parser->importSiteDefinition($definition, dryRun: false);

        self::assertNotNull($capturedContent);
        self::assertSame('pages/pricing-with-calculator', $capturedContent->template);
    }

    #[Test]
    public function contentWithoutTemplateDefaultsToNull(): void
    {
        $this->contentRepo->method('findByImportId')->willReturn(null);

        $capturedContent = null;
        $this->contentRepo->method('save')->willReturnCallback(
            static function (Content $content) use (&$capturedContent): void {
                $capturedContent = $content;
            },
        );

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [
                [
                    'slug' => 'about',
                    'title' => 'About',
                    'body' => '<p>About</p>',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $parser->importSiteDefinition($definition, dryRun: false);

        self::assertNotNull($capturedContent);
        self::assertNull($capturedContent->template);
    }

    //: Edge Cases --

    #[Test]
    public function emptyDefinitionReturnsZeroCounts(): void
    {
        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(0, $result->totalCreated());
        self::assertSame(0, $result->totalUpdated());
        self::assertFalse($result->hasErrors());
    }

    #[Test]
    public function contentWithMissingSlugIsSkippedWithWarning(): void
    {
        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [
                ['title' => 'No slug here', 'body' => '<p>content</p>'],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(0, $result->created['content']);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('missing slug', $result->warnings[0]);
    }

    #[Test]
    public function taxonomyWithMissingSlugIsSkippedWithWarning(): void
    {
        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [
                ['name' => 'No slug'],
            ],
            content: [],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(0, $result->created['taxonomies']);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('missing slug', $result->warnings[0]);
    }

    #[Test]
    public function menuWithMissingLocationIsSkippedWithWarning(): void
    {
        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [],
            menus: [
                ['name' => 'No location'],
            ],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        self::assertSame(0, $result->created['menus']);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('missing location', $result->warnings[0]);
    }

    #[Test]
    public function dryRunDoesNotCallSave(): void
    {
        $contentRepo = $this->createMock(ContentRepositoryInterface::class);
        $contentRepo->method('findByImportId')->willReturn(null);
        $contentRepo->expects(self::never())->method('save');

        $securityConfig = new CmsSecurityConfig();
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        $parser = new SiteDefinitionParser(
            $contentRepo,
            $this->taxonomyService,
            $this->taxonomyRepo,
            $this->menuRepo,
            $this->mediaService,
            $this->settingsService,
            $this->redirectRepo,
            $this->sitemapGenerator,
            $httpClient,
            new ImportConfig(),
            null,
        );

        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [
                ['slug' => 'test', 'title' => 'Test', 'body' => '<p>test</p>'],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $parser->importSiteDefinition($definition, dryRun: true);
    }

    #[Test]
    public function taxonomyTermWithImportIdIsUpdatedOnReimport(): void
    {
        $existingTerm = new TaxonomyTerm(
            id: 'existing-term-id',
            taxonomyId: 'tax-id',
            tenantId: null,
            parentId: null,
            sortOrder: 0,
            createdAt: new DateTimeImmutable(),
            importId: 'term:php',
        );
        $this->taxonomyRepo->method('findByImportId')->willReturn(null);
        $this->taxonomyRepo->method('findTermByImportId')->willReturn($existingTerm);

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [
                [
                    'slug' => 'tags',
                    'name' => 'Tags',
                    'terms' => [
                        ['slug' => 'php', 'name' => 'PHP', 'import_id' => 'term:php'],
                    ],
                ],
            ],
            content: [],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        // Taxonomy created (no import_id) + term updated (has import_id)
        self::assertGreaterThan(0, $result->updated['taxonomies']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function templateVariantProvider(): iterable
    {
        yield 'standard template' => [
            ['slug' => 'page1', 'title' => 'P1', 'template' => 'pages/default', 'body' => ''],
            'pages/default',
        ];
        yield 'nested template path' => [
            ['slug' => 'page2', 'title' => 'P2', 'template' => 'pages/pricing-with-calculator', 'body' => ''],
            'pages/pricing-with-calculator',
        ];
    }

    /**
     * @param array<string, mixed> $contentItem
     */
    #[Test]
    #[DataProvider('templateVariantProvider')]
    public function contentImportUsesTemplateFromData(array $contentItem, string $expectedTemplate): void
    {
        $this->contentRepo->method('findByImportId')->willReturn(null);

        $capturedContent = null;
        $this->contentRepo->method('save')->willReturnCallback(
            static function (Content $content) use (&$capturedContent): void {
                $capturedContent = $content;
            },
        );

        $parser = $this->createParser();
        $definition = new SiteDefinition(
            site: ['url' => 'https://example.com'],
            taxonomies: [],
            content: [$contentItem],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        $parser->importSiteDefinition($definition, dryRun: false);

        self::assertNotNull($capturedContent);
        self::assertSame($expectedTemplate, $capturedContent->template);
    }
}
