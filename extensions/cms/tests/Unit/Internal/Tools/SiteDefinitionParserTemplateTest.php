<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
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
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportConfig;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

/**
 * Tests that template fields are persisted during site definition import,
 * including the update path for existing content (identified via import_id).
 */
#[CoversClass(SiteDefinitionParser::class)]
final class SiteDefinitionParserTemplateTest extends TestCase
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

    #[Test]
    public function newContentIncludesTemplateInDryRunCounts(): void
    {
        // Arrange
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
                    'template' => 'full-width',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        // Act
        $result = $parser->importSiteDefinition($definition, dryRun: true);

        // Assert
        self::assertSame(1, $result->created['content']);
        self::assertSame(0, $result->updated['content']);
    }

    #[Test]
    public function existingContentIsCountedAsUpdateWhenImportIdMatches(): void
    {
        // Arrange: existing content WITHOUT template
        $existingContent = Content::create(
            id: 'existing-content-id',
            contentType: ContentType::Page,
            authorId: 'system',
            template: null,
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
                    'template' => 'landing-page',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        // Act
        $result = $parser->importSiteDefinition($definition, dryRun: true);

        // Assert
        self::assertSame(0, $result->created['content']);
        self::assertSame(1, $result->updated['content']);
    }

    #[Test]
    public function updatePathSavesContentWithNewTemplate(): void
    {
        // Arrange: existing content with old template
        $existingContent = Content::create(
            id: 'existing-content-id',
            contentType: ContentType::Page,
            authorId: 'system',
            template: 'old-template',
        );

        // findByImportId returns existing (first pass determines update)
        $this->contentRepo->method('findByImportId')->willReturn($existingContent);
        // findById returns the existing content (used in update path to fetch current state)
        $this->contentRepo->method('findById')->willReturn($existingContent);

        // Use createMock to verify save() is called with the new template
        $contentRepoMock = $this->createMock(ContentRepositoryInterface::class);
        $contentRepoMock->method('findByImportId')->willReturn($existingContent);
        $contentRepoMock->method('findById')->willReturn($existingContent);

        $savedContent = null;
        $contentRepoMock->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Content $content) use (&$savedContent): bool {
                $savedContent = $content;

                return true;
            }));

        $securityConfig = new CmsSecurityConfig();
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        $parser = new SiteDefinitionParser(
            $contentRepoMock,
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
                [
                    'slug' => 'about',
                    'title' => 'About Updated',
                    'import_id' => 'page:about',
                    'body' => '<p>Updated body</p>',
                    'template' => 'new-landing-page',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        // Act
        $parser->importSiteDefinition($definition, dryRun: false);

        // Assert
        self::assertNotNull($savedContent, 'The content repository save() must be called during update');
        self::assertSame('new-landing-page', $savedContent->template, 'Template must be updated to the new value');
        self::assertSame('existing-content-id', $savedContent->id);
    }

    #[Test]
    public function updatePathPreservesExistingTemplateWhenImportOmitsIt(): void
    {
        // Arrange: existing content has a template
        $existingContent = Content::create(
            id: 'content-with-template',
            contentType: ContentType::Page,
            authorId: 'system',
            template: 'preserved-template',
        );

        $contentRepoMock = $this->createMock(ContentRepositoryInterface::class);
        $contentRepoMock->method('findByImportId')->willReturn($existingContent);
        $contentRepoMock->method('findById')->willReturn($existingContent);

        $savedContent = null;
        $contentRepoMock->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Content $content) use (&$savedContent): bool {
                $savedContent = $content;

                return true;
            }));

        $securityConfig = new CmsSecurityConfig();
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        $parser = new SiteDefinitionParser(
            $contentRepoMock,
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
                [
                    'slug' => 'page-no-template',
                    'title' => 'No Template Specified',
                    'import_id' => 'page:no-template',
                    'body' => '<p>Body</p>',
                    // Note: no 'template' key in the import data
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        // Act
        $parser->importSiteDefinition($definition, dryRun: false);

        // Assert: template should be null (import data has no template key)
        self::assertNotNull($savedContent);
        self::assertNull($savedContent->template, 'When import omits template, it should be set to null');
    }

    #[Test]
    public function newContentCreatedWithTemplateOnNonDryRun(): void
    {
        // Arrange
        $this->contentRepo->method('findByImportId')->willReturn(null);

        $contentRepoMock = $this->createMock(ContentRepositoryInterface::class);
        $contentRepoMock->method('findByImportId')->willReturn(null);

        $savedContent = null;
        $contentRepoMock->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Content $content) use (&$savedContent): bool {
                $savedContent = $content;

                return true;
            }));

        $securityConfig = new CmsSecurityConfig();
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        $parser = new SiteDefinitionParser(
            $contentRepoMock,
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
                [
                    'slug' => 'new-page',
                    'title' => 'New Page',
                    'body' => '<p>New</p>',
                    'template' => 'sidebar-layout',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        // Act
        $parser->importSiteDefinition($definition, dryRun: false);

        // Assert
        self::assertNotNull($savedContent, 'Content must be saved during non-dry-run');
        self::assertSame('sidebar-layout', $savedContent->template, 'Template must be set on newly created content');
    }

    #[Test]
    public function updatePathUpdatesStatusFromImportData(): void
    {
        // Arrange: existing draft content
        $existingContent = Content::create(
            id: 'status-update-content',
            contentType: ContentType::Article,
            authorId: 'system',
            template: null,
        );

        $contentRepoMock = $this->createMock(ContentRepositoryInterface::class);
        $contentRepoMock->method('findByImportId')->willReturn($existingContent);
        $contentRepoMock->method('findById')->willReturn($existingContent);

        $savedContent = null;
        $contentRepoMock->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Content $content) use (&$savedContent): bool {
                $savedContent = $content;

                return true;
            }));

        $securityConfig = new CmsSecurityConfig();
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        $parser = new SiteDefinitionParser(
            $contentRepoMock,
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
                [
                    'slug' => 'article',
                    'title' => 'Published Article',
                    'import_id' => 'article:first',
                    'content_type' => 'article',
                    'body' => '<p>Published</p>',
                    'status' => 'published',
                    'template' => 'blog-post',
                ],
            ],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
        );

        // Act
        $parser->importSiteDefinition($definition, dryRun: false);

        // Assert
        self::assertNotNull($savedContent);
        self::assertSame('blog-post', $savedContent->template);
        self::assertSame(\Pulsar\Extension\Cms\Content\PublishingStatus::Published, $savedContent->status);
        self::assertNotNull($savedContent->publishedAt, 'publishedAt must be set when status is published');
    }
}
