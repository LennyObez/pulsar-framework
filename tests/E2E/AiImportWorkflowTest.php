<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

use function count;
use function json_encode;

#[CoversClass(SiteDefinition::class)]
#[CoversClass(ImportResult::class)]
final class AiImportWorkflowTest extends TestCase
{
    #[Test]
    public function test_full_ai_import_workflow(): void
    {
        // Step 1: Build site definition JSON
        $siteDefinitionData = [
            'version' => '1.0',
            'site' => [
                'name' => 'AI-Generated Site',
                'locales' => ['en', 'fr'],
                'default_locale' => 'en',
            ],
            'taxonomies' => [
                [
                    'id' => 'tax-001',
                    'name' => 'Categories',
                    'terms' => [
                        ['slug' => 'tech', 'name' => 'Technology'],
                        ['slug' => 'science', 'name' => 'Science'],
                    ],
                ],
            ],
            'content' => [
                [
                    'id' => 'c-001',
                    'type' => 'page',
                    'title' => 'Home',
                    'slug' => 'home',
                    'body' => '<p>Welcome to AI Site</p>',
                ],
                [
                    'id' => 'c-002',
                    'type' => 'article',
                    'title' => 'First Post',
                    'slug' => 'first-post',
                    'body' => '<p>Hello World</p>',
                    'taxonomy_ref' => 'tax-001',
                ],
            ],
            'menus' => [
                [
                    'id' => 'm-001',
                    'name' => 'Main Navigation',
                    'items' => [
                        ['label' => 'Home', 'content_ref' => 'content_ref:c-001'],
                        ['label' => 'Blog', 'content_ref' => 'content_ref:c-002'],
                    ],
                ],
            ],
            'media' => [
                [
                    'ref' => 'media://hero.jpg',
                    'source' => 'https://example.com/images/hero.jpg',
                    'alt' => 'Hero Image',
                ],
            ],
            'redirects' => [
                ['from' => '/old-home', 'to' => '/home', 'status' => 301],
            ],
            'seo' => [
                'robots' => 'index,follow',
                'sitemap' => true,
                'structured_data' => ['@type' => 'WebSite', 'name' => 'AI Site'],
            ],
        ];

        $json = json_encode($siteDefinitionData, JSON_THROW_ON_ERROR);

        // Step 2: Parse and validate
        $definition = SiteDefinition::fromJson($json);

        self::assertSame('AI-Generated Site', $definition->site['name']);
        self::assertCount(1, $definition->taxonomies);
        self::assertCount(2, $definition->content);
        self::assertCount(1, $definition->menus);
        self::assertCount(1, $definition->media);
        self::assertCount(1, $definition->redirects);
        self::assertSame('index,follow', $definition->seo['robots']);

        // Step 3: Dry-run import
        $service = $this->createImportService();
        $dryRunResult = $service->importSiteDefinition($json, dryRun: true);

        self::assertTrue($dryRunResult->dryRun);
        self::assertSame([], $dryRunResult->errors);
        self::assertGreaterThan(0, $dryRunResult->created['content'] ?? 0);
        self::assertGreaterThan(0, $dryRunResult->created['taxonomies'] ?? 0);

        // Step 4: Execute import
        $executeResult = $service->importSiteDefinition($json, dryRun: false);

        self::assertFalse($executeResult->dryRun);
        self::assertSame([], $executeResult->errors);

        // Step 5: Verify all entities created
        self::assertSame(2, $executeResult->created['content']);
        self::assertSame(1, $executeResult->created['taxonomies']);
        self::assertSame(1, $executeResult->created['menus']);
        self::assertSame(1, $executeResult->created['media']);
        self::assertSame(1, $executeResult->created['redirects']);
        self::assertSame(1, $executeResult->created['seo']);

        // Verify dry-run counts match execute counts
        self::assertSame($dryRunResult->created, $executeResult->created);
    }

    #[Test]
    public function test_invalid_site_definition_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SiteDefinition::fromJson(json_encode(['invalid' => true], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function test_import_with_content_references(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Ref Test'],
            'content' => [
                ['id' => 'parent', 'type' => 'page', 'title' => 'Parent'],
                ['id' => 'child', 'type' => 'page', 'title' => 'Child', 'parent_ref' => 'content_ref:parent'],
            ],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertCount(2, $def->content);
        self::assertSame('content_ref:parent', $def->content[1]['parent_ref']);
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
