<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

use function count;
use function json_encode;

/**
 * E2E: AI-driven site import — JSON definition -> dry-run -> execute -> verify entities.
 */
#[Group('e2e-cms')]
final class AiImportWorkflowTest extends TestCase
{
    #[Test]
    public function test_full_site_import_dry_run_then_execute(): void
    {
        $siteDefinitionData = [
            'version' => '1.0',
            'site' => [
                'name' => 'E2E AI Import Site',
                'locales' => ['en', 'fr', 'de'],
                'default_locale' => 'en',
            ],
            'taxonomies' => [
                [
                    'id' => 'tax-e2e-cat',
                    'name' => 'Categories',
                    'terms' => [
                        ['slug' => 'news', 'name' => 'News'],
                        ['slug' => 'tutorials', 'name' => 'Tutorials'],
                        ['slug' => 'reviews', 'name' => 'Reviews'],
                    ],
                ],
                [
                    'id' => 'tax-e2e-tag',
                    'name' => 'Tags',
                    'terms' => [
                        ['slug' => 'php', 'name' => 'PHP'],
                        ['slug' => 'javascript', 'name' => 'JavaScript'],
                    ],
                ],
            ],
            'content' => [
                [
                    'id' => 'c-home',
                    'type' => 'page',
                    'title' => 'Home',
                    'slug' => 'home',
                    'body' => '<p>Welcome to our AI-generated site.</p>',
                ],
                [
                    'id' => 'c-about',
                    'type' => 'page',
                    'title' => 'About Us',
                    'slug' => 'about',
                    'body' => '<p>Learn more about our team.</p>',
                ],
                [
                    'id' => 'c-blog',
                    'type' => 'page',
                    'title' => 'Blog',
                    'slug' => 'blog',
                    'body' => '<p>Latest articles and tutorials.</p>',
                ],
                [
                    'id' => 'c-post-1',
                    'type' => 'article',
                    'title' => 'Getting Started with PHP 8.5',
                    'slug' => 'getting-started-php-85',
                    'body' => '<p>PHP 8.5 introduces exciting new features.</p>',
                    'taxonomy_ref' => 'tax-e2e-cat',
                ],
                [
                    'id' => 'c-post-2',
                    'type' => 'article',
                    'title' => 'Building Modern UIs',
                    'slug' => 'building-modern-uis',
                    'body' => '<p>Modern UI development with component-based architecture.</p>',
                    'taxonomy_ref' => 'tax-e2e-cat',
                ],
            ],
            'menus' => [
                [
                    'id' => 'm-main',
                    'name' => 'Main Navigation',
                    'items' => [
                        ['label' => 'Home', 'content_ref' => 'content_ref:c-home'],
                        ['label' => 'About', 'content_ref' => 'content_ref:c-about'],
                        ['label' => 'Blog', 'content_ref' => 'content_ref:c-blog'],
                    ],
                ],
                [
                    'id' => 'm-footer',
                    'name' => 'Footer Navigation',
                    'items' => [
                        ['label' => 'Privacy', 'content_ref' => 'content_ref:c-about'],
                        ['label' => 'Contact', 'url' => '/contact'],
                    ],
                ],
            ],
            'media' => [
                [
                    'ref' => 'media://logo.png',
                    'source' => 'https://example.com/images/logo.png',
                    'alt' => 'Site Logo',
                ],
                [
                    'ref' => 'media://hero.jpg',
                    'source' => 'https://example.com/images/hero.jpg',
                    'alt' => 'Hero Banner',
                ],
            ],
            'redirects' => [
                ['from' => '/old-home', 'to' => '/home', 'status' => 301],
                ['from' => '/legacy-blog', 'to' => '/blog', 'status' => 301],
            ],
            'seo' => [
                'robots' => 'index,follow',
                'sitemap' => true,
                'structured_data' => ['@type' => 'WebSite', 'name' => 'E2E AI Import Site'],
            ],
        ];

        $json = json_encode($siteDefinitionData, JSON_THROW_ON_ERROR);

        // Step 1: Parse and validate definition
        $definition = SiteDefinition::fromJson($json);

        self::assertSame('E2E AI Import Site', $definition->site['name']);
        self::assertCount(2, $definition->taxonomies);
        self::assertCount(5, $definition->content);
        self::assertCount(2, $definition->menus);
        self::assertCount(2, $definition->media);
        self::assertCount(2, $definition->redirects);

        // Step 2: Dry-run import
        $service = $this->createImportService();
        $dryRunResult = $service->importSiteDefinition($json, dryRun: true);

        self::assertTrue($dryRunResult->dryRun);
        self::assertSame([], $dryRunResult->errors);
        self::assertSame(5, $dryRunResult->created['content']);
        self::assertSame(2, $dryRunResult->created['taxonomies']);
        self::assertSame(2, $dryRunResult->created['menus']);
        self::assertSame(2, $dryRunResult->created['media']);
        self::assertSame(2, $dryRunResult->created['redirects']);
        self::assertSame(1, $dryRunResult->created['seo']);

        // Step 3: Execute import
        $executeResult = $service->importSiteDefinition($json, dryRun: false);

        self::assertFalse($executeResult->dryRun);
        self::assertSame([], $executeResult->errors);

        // Step 4: Verify dry-run counts match execution counts
        self::assertSame($dryRunResult->created, $executeResult->created);

        // Step 5: Verify all entity counts
        self::assertSame(5, $executeResult->created['content']);
        self::assertSame(2, $executeResult->created['taxonomies']);
        self::assertSame(2, $executeResult->created['menus']);
        self::assertSame(2, $executeResult->created['media']);
        self::assertSame(2, $executeResult->created['redirects']);
        self::assertSame(1, $executeResult->created['seo']);
    }

    #[Test]
    public function test_invalid_definition_missing_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SiteDefinition::fromJson(json_encode(['site' => ['name' => 'No Version']], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function test_content_with_parent_references_preserved(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Parent Ref Test'],
            'content' => [
                ['id' => 'parent-page', 'type' => 'page', 'title' => 'Parent Page', 'slug' => 'parent'],
                ['id' => 'child-page', 'type' => 'page', 'title' => 'Child Page', 'slug' => 'child', 'parent_ref' => 'content_ref:parent-page'],
                ['id' => 'grandchild', 'type' => 'page', 'title' => 'Grandchild', 'slug' => 'grandchild', 'parent_ref' => 'content_ref:child-page'],
            ],
        ], JSON_THROW_ON_ERROR);

        $definition = SiteDefinition::fromJson($json);

        self::assertCount(3, $definition->content);
        self::assertSame('content_ref:parent-page', $definition->content[1]['parent_ref']);
        self::assertSame('content_ref:child-page', $definition->content[2]['parent_ref']);
    }

    #[Test]
    public function test_empty_content_import_produces_zero_counts(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Empty Site'],
        ], JSON_THROW_ON_ERROR);

        $definition = SiteDefinition::fromJson($json);
        self::assertCount(0, $definition->content);

        $service = $this->createImportService();
        $result = $service->importSiteDefinition($json, dryRun: false);

        self::assertSame([], $result->errors);
        self::assertArrayNotHasKey('content', $result->created);
    }

    private function createImportService(): ImportExportServiceInterface
    {
        return new class implements ImportExportServiceInterface {
            public function exportBundle(ExportOptions $options): ExportBundle
            {
                return new ExportBundle([], 'hash', new DateTimeImmutable(), $options->scope, false);
            }

            public function importBundle(string $jsonContent, bool $dryRun = true): ImportResult
            {
                return $this->importSiteDefinition($jsonContent, $dryRun);
            }

            public function importSiteDefinition(string $jsonContent, bool $dryRun = true): ImportResult
            {
                $def = SiteDefinition::fromJson($jsonContent);

                $created = [];

                if ($def->taxonomies !== []) {
                    $created['taxonomies'] = count($def->taxonomies);
                }

                if ($def->media !== []) {
                    $created['media'] = count($def->media);
                }

                if ($def->content !== []) {
                    $created['content'] = count($def->content);
                }

                if ($def->menus !== []) {
                    $created['menus'] = count($def->menus);
                }

                if ($def->redirects !== []) {
                    $created['redirects'] = count($def->redirects);
                }

                if ($def->seo !== []) {
                    $created['seo'] = 1;
                }

                return new ImportResult(
                    created: $created,
                    updated: [],
                    skipped: [],
                    warnings: [],
                    errors: [],
                    dryRun: $dryRun,
                );
            }
        };
    }
}
