<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
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

use function memory_get_peak_usage;
use function microtime;
use function range;
use function sprintf;

#[CoversClass(SiteDefinitionParser::class)]
#[Group('benchmark')]
final class LargeImportBenchmarkTest extends TestCase
{
    private const int TAXONOMY_COUNT = 5;
    private const int TERMS_PER_TAXONOMY = 50;
    private const int PAGE_COUNT = 200;
    private const int ARTICLE_COUNT = 300;
    private const int MEDIA_COUNT = 200;
    private const int MENU_COUNT = 5;
    private const int ITEMS_PER_MENU = 50;
    private const int REDIRECT_COUNT = 50;
    private const int LOCALES = 2;

    private const float MAX_WALL_TIME_SECONDS = 60.0;
    private const int MAX_MEMORY_BYTES = 256 * 1024 * 1024; // 256 MB

    #[Test]
    public function dry_run_import_of_1000_plus_items_completes_within_budget(): void
    {
        // Arrange: build mocks that just count calls
        $callCounts = [
            'contentRepository.save' => 0,
            'taxonomyRepository.save' => 0,
            'taxonomyRepository.saveTerm' => 0,
            'taxonomyService.attachTerms' => 0,
            'menuRepository.save' => 0,
            'menuRepository.saveItem' => 0,
            'mediaService.upload' => 0,
            'settingsService.set' => 0,
            'redirectRepository.save' => 0,
            'sitemapGenerator.generateIndex' => 0,
        ];

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('save')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['contentRepository.save']++;
            },
        );

        $taxRepo = $this->createStub(TaxonomyRepositoryInterface::class);
        $taxRepo->method('save')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['taxonomyRepository.save']++;
            },
        );
        $taxRepo->method('saveTerm')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['taxonomyRepository.saveTerm']++;
            },
        );

        $taxService = $this->createStub(TaxonomyServiceInterface::class);
        $taxService->method('attachTerms')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['taxonomyService.attachTerms']++;
            },
        );

        $menuRepo = $this->createStub(MenuRepositoryInterface::class);
        $menuRepo->method('save')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['menuRepository.save']++;
            },
        );
        $menuRepo->method('saveItem')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['menuRepository.saveItem']++;
            },
        );

        $mediaService = $this->createStub(MediaServiceInterface::class);
        $mediaService->method('upload')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['mediaService.upload']++;
            },
        );

        $settingsService = $this->createStub(SettingsServiceInterface::class);
        $settingsService->method('set')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['settingsService.set']++;
            },
        );

        $redirectRepo = $this->createStub(RedirectRepositoryInterface::class);
        $redirectRepo->method('save')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['redirectRepository.save']++;
            },
        );

        $sitemapGenerator = $this->createStub(SitemapGeneratorInterface::class);
        $sitemapGenerator->method('generateIndex')->willReturnCallback(
            static function () use (&$callCounts): string {
                $callCounts['sitemapGenerator.generateIndex']++;

                return '';
            },
        );

        $httpClient = new SafeHttpClient(new CmsSecurityConfig(), new NullLogger());
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $config = new ImportConfig(
            allowExternalMediaDownload: false,
        );

        $parser = new SiteDefinitionParser(
            contentRepository: $contentRepo,
            taxonomyService: $taxService,
            taxonomyRepository: $taxRepo,
            menuRepository: $menuRepo,
            mediaService: $mediaService,
            settingsService: $settingsService,
            redirectRepository: $redirectRepo,
            sitemapGenerator: $sitemapGenerator,
            httpClient: $httpClient,
            config: $config,
            auditLogger: $auditLogger,
        );

        $definition = $this->buildLargeSiteDefinition();

        // Act: run in dry-run mode and measure
        $memBefore = memory_get_peak_usage(true);
        $startTime = microtime(true);

        $result = $parser->importSiteDefinition($definition, dryRun: true);

        $elapsed = microtime(true) - $startTime;
        $peakMemory = memory_get_peak_usage(true);
        $memUsed = $peakMemory - $memBefore;

        // Output stats for CI visibility
        fwrite(STDERR, sprintf(
            "\n[Benchmark] Dry-run import: %.3f s | Peak memory: %.1f MB | Delta: %.1f MB\n",
            $elapsed,
            $peakMemory / 1024 / 1024,
            $memUsed / 1024 / 1024,
        ));
        fwrite(STDERR, sprintf(
            "[Benchmark] Items: taxonomies=%d, content=%d, media=%d, menus=%d, redirects=%d\n",
            $result->created['taxonomies'] ?? 0,
            $result->created['content'] ?? 0,
            $result->created['media'] ?? 0,
            $result->created['menus'] ?? 0,
            $result->created['redirects'] ?? 0,
        ));

        // Assert: performance budgets
        self::assertLessThan(
            self::MAX_WALL_TIME_SECONDS,
            $elapsed,
            sprintf('Import took %.3f s, exceeding the %.0f s budget', $elapsed, self::MAX_WALL_TIME_SECONDS),
        );
        self::assertLessThan(
            self::MAX_MEMORY_BYTES,
            $peakMemory,
            sprintf(
                'Peak memory %.1f MB exceeds the %d MB budget',
                $peakMemory / 1024 / 1024,
                self::MAX_MEMORY_BYTES / 1024 / 1024,
            ),
        );

        // Assert: correct item counts
        $totalTaxonomyItems = self::TAXONOMY_COUNT + (self::TAXONOMY_COUNT * self::TERMS_PER_TAXONOMY);
        self::assertSame($totalTaxonomyItems, $result->created['taxonomies']);

        $totalContent = self::PAGE_COUNT + self::ARTICLE_COUNT;
        self::assertSame($totalContent, $result->created['content']);

        self::assertSame(self::MEDIA_COUNT, $result->created['media']);
        self::assertSame(self::REDIRECT_COUNT, $result->created['redirects']);

        // Menus: each menu counts 1 + its items in dry-run mode
        $totalMenuItems = self::MENU_COUNT + (self::MENU_COUNT * self::ITEMS_PER_MENU);
        self::assertSame($totalMenuItems, $result->created['menus']);

        // Dry-run should produce no errors
        self::assertTrue($result->dryRun);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function non_dry_run_import_of_1000_plus_items_completes_within_budget(): void
    {
        $callCounts = [
            'contentRepository.save' => 0,
            'taxonomyRepository.save' => 0,
            'taxonomyRepository.saveTerm' => 0,
            'taxonomyService.attachTerms' => 0,
            'menuRepository.save' => 0,
            'menuRepository.saveItem' => 0,
            'settingsService.set' => 0,
            'redirectRepository.save' => 0,
            'sitemapGenerator.generateIndex' => 0,
        ];

        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('save')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['contentRepository.save']++;
            },
        );

        $taxRepo = $this->createStub(TaxonomyRepositoryInterface::class);
        $taxRepo->method('save')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['taxonomyRepository.save']++;
            },
        );
        $taxRepo->method('saveTerm')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['taxonomyRepository.saveTerm']++;
            },
        );

        $taxService = $this->createStub(TaxonomyServiceInterface::class);
        $taxService->method('attachTerms')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['taxonomyService.attachTerms']++;
            },
        );

        $menuRepo = $this->createStub(MenuRepositoryInterface::class);
        $menuRepo->method('save')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['menuRepository.save']++;
            },
        );
        $menuRepo->method('saveItem')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['menuRepository.saveItem']++;
            },
        );

        $mediaService = $this->createStub(MediaServiceInterface::class);

        $settingsService = $this->createStub(SettingsServiceInterface::class);
        $settingsService->method('set')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['settingsService.set']++;
            },
        );

        $redirectRepo = $this->createStub(RedirectRepositoryInterface::class);
        $redirectRepo->method('save')->willReturnCallback(
            static function () use (&$callCounts): void {
                $callCounts['redirectRepository.save']++;
            },
        );

        $sitemapGenerator = $this->createStub(SitemapGeneratorInterface::class);
        $sitemapGenerator->method('generateIndex')->willReturnCallback(
            static function () use (&$callCounts): string {
                $callCounts['sitemapGenerator.generateIndex']++;

                return '';
            },
        );

        $httpClient = new SafeHttpClient(new CmsSecurityConfig(), new NullLogger());
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $config = new ImportConfig(
            allowExternalMediaDownload: false,
        );

        $parser = new SiteDefinitionParser(
            contentRepository: $contentRepo,
            taxonomyService: $taxService,
            taxonomyRepository: $taxRepo,
            menuRepository: $menuRepo,
            mediaService: $mediaService,
            settingsService: $settingsService,
            redirectRepository: $redirectRepo,
            sitemapGenerator: $sitemapGenerator,
            httpClient: $httpClient,
            config: $config,
            auditLogger: $auditLogger,
        );

        $definition = $this->buildLargeSiteDefinition();

        // Act: run non-dry-run and measure
        $startTime = microtime(true);

        $result = $parser->importSiteDefinition($definition, dryRun: false);

        $elapsed = microtime(true) - $startTime;
        $peakMemory = memory_get_peak_usage(true);

        // Output stats
        fwrite(STDERR, sprintf(
            "\n[Benchmark] Full import: %.3f s | Peak memory: %.1f MB\n",
            $elapsed,
            $peakMemory / 1024 / 1024,
        ));
        fwrite(STDERR, sprintf(
            "[Benchmark] Repository calls: content.save=%d, taxonomy.save=%d, term.save=%d, menu.save=%d, menuItem.save=%d, redirect.save=%d, settings.set=%d\n",
            $callCounts['contentRepository.save'],
            $callCounts['taxonomyRepository.save'],
            $callCounts['taxonomyRepository.saveTerm'],
            $callCounts['menuRepository.save'],
            $callCounts['menuRepository.saveItem'],
            $callCounts['redirectRepository.save'],
            $callCounts['settingsService.set'],
        ));

        // Assert: performance budgets
        self::assertLessThan(
            self::MAX_WALL_TIME_SECONDS,
            $elapsed,
            sprintf('Import took %.3f s, exceeding the %.0f s budget', $elapsed, self::MAX_WALL_TIME_SECONDS),
        );
        self::assertLessThan(
            self::MAX_MEMORY_BYTES,
            $peakMemory,
            sprintf(
                'Peak memory %.1f MB exceeds the %d MB budget',
                $peakMemory / 1024 / 1024,
                self::MAX_MEMORY_BYTES / 1024 / 1024,
            ),
        );

        // Assert: repository call counts match expected
        self::assertSame(self::TAXONOMY_COUNT, $callCounts['taxonomyRepository.save']);
        self::assertSame(
            self::TAXONOMY_COUNT * self::TERMS_PER_TAXONOMY,
            $callCounts['taxonomyRepository.saveTerm'],
        );
        self::assertSame(
            self::PAGE_COUNT + self::ARTICLE_COUNT,
            $callCounts['contentRepository.save'],
        );
        self::assertSame(self::MENU_COUNT, $callCounts['menuRepository.save']);
        self::assertSame(
            self::MENU_COUNT * self::ITEMS_PER_MENU,
            $callCounts['menuRepository.saveItem'],
        );
        self::assertSame(self::REDIRECT_COUNT, $callCounts['redirectRepository.save']);
        self::assertSame(1, $callCounts['sitemapGenerator.generateIndex']);

        // Non-dry-run should confirm
        self::assertFalse($result->dryRun);
        self::assertSame([], $result->errors);
    }

    /**
     * Build a synthetic SiteDefinition with 1000+ items:
     * - 5 taxonomies x 50 terms = 250 terms
     * - 200 pages x 2 locales (translations in body)
     * - 300 articles x 2 locales
     * - 200 media references
     * - 5 menus x 50 items = 250 menu items
     * - 50 redirects
     * Total: ~1,255 discrete items
     */
    private function buildLargeSiteDefinition(): SiteDefinition
    {
        return new SiteDefinition(
            site: [
                'name' => 'Benchmark Site',
                'url' => 'https://benchmark.example.com',
                'locales' => ['en', 'fr'],
                'default_locale' => 'en',
                'tenant_id' => 'bench-tenant',
                'settings' => [
                    'timezone' => 'UTC',
                    'date_format' => 'Y-m-d',
                ],
            ],
            taxonomies: $this->generateTaxonomies(),
            content: $this->generateContent(),
            menus: $this->generateMenus(),
            media: $this->generateMedia(),
            redirects: $this->generateRedirects(),
            seo: [
                'robots_txt' => 'User-agent: *\nAllow: /',
                'sitemap_enabled' => true,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateTaxonomies(): array
    {
        $taxonomies = [];

        foreach (range(1, self::TAXONOMY_COUNT) as $t) {
            $terms = [];

            foreach (range(1, self::TERMS_PER_TAXONOMY) as $term) {
                $terms[] = [
                    'slug' => "term-{$t}-{$term}",
                    'name' => "Term {$t}.{$term}",
                    'description' => "Description for term {$t}.{$term}",
                    'sort_order' => $term,
                ];
            }

            $taxonomies[] = [
                'slug' => "taxonomy-{$t}",
                'name' => "Taxonomy {$t}",
                'description' => "Benchmark taxonomy {$t}",
                'hierarchical' => $t % 2 === 0,
                'locale' => 'en',
                'terms' => $terms,
            ];
        }

        return $taxonomies;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateContent(): array
    {
        $content = [];

        // 200 pages
        foreach (range(1, self::PAGE_COUNT) as $p) {
            $content[] = [
                'content_type' => 'page',
                'slug' => "page-{$p}",
                'title' => "Page {$p}",
                'body' => "Body content for page {$p}. Includes media://image-" . ($p % self::MEDIA_COUNT + 1) . '.jpg reference.',
                'excerpt' => "Excerpt for page {$p}",
                'locale' => $p % self::LOCALES === 0 ? 'fr' : 'en',
                'template' => 'default',
                'author_id' => 'bench-author',
                'taxonomy_terms' => [
                    'taxonomy-1:term-1-' . ($p % self::TERMS_PER_TAXONOMY + 1),
                ],
                'blocks' => [
                    ['type' => 'text', 'data' => ['content' => "Block text for page {$p}"]],
                    ['type' => 'image', 'data' => ['src' => 'media://image-' . ($p % self::MEDIA_COUNT + 1) . '.jpg']],
                ],
                'meta_title' => "Page {$p} | Benchmark",
                'meta_description' => "Meta description for benchmark page {$p}",
            ];
        }

        // 300 articles
        foreach (range(1, self::ARTICLE_COUNT) as $a) {
            $content[] = [
                'content_type' => 'article',
                'slug' => "article-{$a}",
                'title' => "Article {$a}",
                'body' => "Body content for article {$a}. Longer body with media://image-" . ($a % self::MEDIA_COUNT + 1) . '.jpg embedded.',
                'excerpt' => "Excerpt for article {$a}",
                'locale' => $a % self::LOCALES === 0 ? 'fr' : 'en',
                'template' => 'blog',
                'author_id' => 'bench-author',
                'taxonomy_terms' => [
                    'taxonomy-2:term-2-' . ($a % self::TERMS_PER_TAXONOMY + 1),
                    'taxonomy-3:term-3-' . ($a % self::TERMS_PER_TAXONOMY + 1),
                ],
                'blocks' => [
                    ['type' => 'text', 'data' => ['content' => "Article {$a} intro paragraph"]],
                    ['type' => 'text', 'data' => ['content' => "Article {$a} body content"]],
                ],
                'og_image' => 'image-' . ($a % self::MEDIA_COUNT + 1) . '.jpg',
                'meta_title' => "Article {$a} | Benchmark",
                'meta_description' => "Meta description for benchmark article {$a}",
            ];
        }

        return $content;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateMenus(): array
    {
        $menus = [];

        foreach (range(1, self::MENU_COUNT) as $m) {
            $items = [];

            foreach (range(1, self::ITEMS_PER_MENU) as $i) {
                $items[] = [
                    'label' => "Menu {$m} Item {$i}",
                    'content_ref' => 'page:page-' . ($i % self::PAGE_COUNT + 1),
                    'target' => '_self',
                    'sort_order' => $i,
                    'visible' => true,
                ];
            }

            $menus[] = [
                'location' => "menu-location-{$m}",
                'name' => "Menu {$m}",
                'locale' => 'en',
                'items' => $items,
            ];
        }

        return $menus;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateMedia(): array
    {
        $media = [];

        foreach (range(1, self::MEDIA_COUNT) as $m) {
            $media[] = [
                'ref' => "image-{$m}.jpg",
                'filename' => "image-{$m}.jpg",
                'mime_type' => 'image/jpeg',
                // No 'source' key: no external download, just ref tracking
            ];
        }

        return $media;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateRedirects(): array
    {
        $redirects = [];

        foreach (range(1, self::REDIRECT_COUNT) as $r) {
            $redirects[] = [
                'from' => "/old-path-{$r}",
                'to' => "/new-path-{$r}",
                'status_code' => $r % 2 === 0 ? 301 : 302,
                'reason' => "Benchmark redirect {$r}",
            ];
        }

        return $redirects;
    }
}
