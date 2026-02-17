<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
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

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies that SiteDefinitionParser auto-configures the homepage
 * content ID when importing content with is_homepage: true or empty slug.
 */
#[CoversClass(SiteDefinitionParser::class)]
final class SiteDefinitionParserHomepageTest extends TestCase
{
    #[Test]
    public function importSetsHomepageContentIdForIsHomepageFlag(): void
    {
        // Arrange: capture what SettingsService receives
        $capturedKey = null;
        $capturedValue = null;

        $settingsService = $this->createStub(SettingsServiceInterface::class);
        $settingsService->method('set')->willReturnCallback(
            function (string $group, string $key, mixed $value) use (&$capturedKey, &$capturedValue): void {
                if ($group === 'site' && $key === 'homepage_content_id') {
                    $capturedKey = $key;
                    $capturedValue = $value;
                }
            },
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findByImportId')->willReturn(null);

        $securityConfig = CmsSecurityConfig::fromArray([]);
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        $parser = new SiteDefinitionParser(
            contentRepository: $contentRepo,
            taxonomyService: $this->createStub(TaxonomyServiceInterface::class),
            taxonomyRepository: $this->createStub(TaxonomyRepositoryInterface::class),
            menuRepository: $this->createStub(MenuRepositoryInterface::class),
            mediaService: $this->createStub(MediaServiceInterface::class),
            settingsService: $settingsService,
            redirectRepository: $this->createStub(RedirectRepositoryInterface::class),
            sitemapGenerator: $this->createStub(SitemapGeneratorInterface::class),
            httpClient: $httpClient,
            config: ImportConfig::fromArray([]),
            auditLogger: $this->createStub(AuditLoggerInterface::class),
        );

        $definition = SiteDefinition::fromJson(json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test Site', 'url' => 'https://example.com'],
            'content' => [
                [
                    'slug' => 'about',
                    'content_type' => 'page',
                    'title' => 'About',
                    'translations' => ['en' => ['title' => 'About', 'body' => '<p>About us</p>']],
                ],
                [
                    'slug' => 'home',
                    'content_type' => 'page',
                    'is_homepage' => true,
                    'title' => 'Home',
                    'translations' => ['en' => ['title' => 'Home', 'body' => '<p>Welcome</p>']],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        // Act
        $parser->importSiteDefinition($definition, dryRun: false);

        // Assert: homepage_content_id was set
        self::assertSame('homepage_content_id', $capturedKey);
        self::assertNotNull($capturedValue, 'homepage_content_id should have been set');
        self::assertNotEmpty($capturedValue, 'homepage_content_id should not be empty');
    }

    #[Test]
    public function importSetsHomepageContentIdForEmptySlug(): void
    {
        // Arrange
        $capturedValue = null;

        $settingsService = $this->createStub(SettingsServiceInterface::class);
        $settingsService->method('set')->willReturnCallback(
            function (string $group, string $key, mixed $value) use (&$capturedValue): void {
                if ($group === 'site' && $key === 'homepage_content_id') {
                    $capturedValue = $value;
                }
            },
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findByImportId')->willReturn(null);

        $securityConfig = CmsSecurityConfig::fromArray([]);
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        $parser = new SiteDefinitionParser(
            contentRepository: $contentRepo,
            taxonomyService: $this->createStub(TaxonomyServiceInterface::class),
            taxonomyRepository: $this->createStub(TaxonomyRepositoryInterface::class),
            menuRepository: $this->createStub(MenuRepositoryInterface::class),
            mediaService: $this->createStub(MediaServiceInterface::class),
            settingsService: $settingsService,
            redirectRepository: $this->createStub(RedirectRepositoryInterface::class),
            sitemapGenerator: $this->createStub(SitemapGeneratorInterface::class),
            httpClient: $httpClient,
            config: ImportConfig::fromArray([]),
            auditLogger: $this->createStub(AuditLoggerInterface::class),
        );

        $definition = SiteDefinition::fromJson(json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test Site', 'url' => 'https://example.com'],
            'content' => [
                [
                    'slug' => '',
                    'content_type' => 'page',
                    'title' => 'Homepage',
                    'translations' => ['en' => ['title' => 'Homepage', 'body' => '<p>Welcome</p>']],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        // Act
        $parser->importSiteDefinition($definition, dryRun: false);

        // Assert
        self::assertNotNull($capturedValue, 'homepage_content_id should be set for content with empty slug');
    }

    #[Test]
    public function dryRunDoesNotSetHomepageContentId(): void
    {
        // Arrange
        $setCalled = false;

        $settingsService = $this->createStub(SettingsServiceInterface::class);
        $settingsService->method('set')->willReturnCallback(
            function () use (&$setCalled): void {
                $setCalled = true;
            },
        );

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findByImportId')->willReturn(null);

        $securityConfig = CmsSecurityConfig::fromArray([]);
        $httpClient = new SafeHttpClient($securityConfig, new NullLogger());

        $parser = new SiteDefinitionParser(
            contentRepository: $contentRepo,
            taxonomyService: $this->createStub(TaxonomyServiceInterface::class),
            taxonomyRepository: $this->createStub(TaxonomyRepositoryInterface::class),
            menuRepository: $this->createStub(MenuRepositoryInterface::class),
            mediaService: $this->createStub(MediaServiceInterface::class),
            settingsService: $settingsService,
            redirectRepository: $this->createStub(RedirectRepositoryInterface::class),
            sitemapGenerator: $this->createStub(SitemapGeneratorInterface::class),
            httpClient: $httpClient,
            config: ImportConfig::fromArray([]),
            auditLogger: $this->createStub(AuditLoggerInterface::class),
        );

        $definition = SiteDefinition::fromJson(json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test Site'],
            'content' => [
                [
                    'slug' => '',
                    'content_type' => 'page',
                    'is_homepage' => true,
                    'title' => 'Home',
                    'translations' => ['en' => ['title' => 'Home', 'body' => '<p>Welcome</p>']],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        // Act
        $parser->importSiteDefinition($definition, dryRun: true);

        // Assert: settings should not be written during dry run
        self::assertFalse($setCalled, 'SettingsService::set() should not be called during dry run');
    }
}
