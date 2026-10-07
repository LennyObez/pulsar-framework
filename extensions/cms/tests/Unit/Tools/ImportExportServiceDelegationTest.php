<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Tools\ExportBundleGenerator;
use Pulsar\Extension\Cms\Internal\Tools\ImportExportService;
use Pulsar\Extension\Cms\Internal\Tools\ImportParser;
use Pulsar\Extension\Cms\Internal\Tools\SiteDefinitionParser;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Tools\ImportConfig;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\ImportExport\ImportRequest;
use Pulsar\ImportExport\ImportResult as ProviderImportResult;
use ReflectionClass;

use function count;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies that ImportExportService correctly delegates extension
 * sections (e.g., 'forum') to the ImportExportRegistry (blocker #4).
 */
final class ImportExportServiceDelegationTest extends TestCase
{
    #[Test]
    public function importUnifiedFileDelegatesForumSectionToRegistry(): void
    {
        $registry = new ImportExportRegistry();

        $forumProvider = $this->createMock(ImportExportProviderInterface::class);
        $forumProvider->method('name')->willReturn('forum');
        $forumProvider->expects($this->once())
            ->method('import')
            ->willReturnCallback(static function (ImportRequest $req): ProviderImportResult {
                /** @var array<string, mixed> $data */
                $data = json_decode($req->content, true, 512, JSON_THROW_ON_ERROR);
                /** @var list<mixed> $cats */
                $cats = $data['categories'] ?? [];
                $catCount = count($cats);

                return new ProviderImportResult(
                    providerName: 'forum',
                    created: ['categories' => $catCount],
                    updated: [],
                    skipped: [],
                    warnings: [],
                    errors: [],
                    dryRun: $req->dryRun,
                );
            });

        $registry->register($forumProvider);

        $service = $this->buildService($registry);

        // JSON with only a 'forum' section (no 'version'/'site' or CMS keys)
        $json = json_encode([
            'forum' => [
                'categories' => [
                    ['slug' => 'general'],
                    ['slug' => 'help'],
                    ['slug' => 'showcase'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importUnifiedFile($json, dryRun: false);

        self::assertSame(3, $result->created['categories'] ?? 0, 'Forum categories must be delegated and counted');
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function importUnifiedFileWarnsWhenRegistryIsNull(): void
    {
        $service = $this->buildService(null);

        $json = json_encode([
            'forum' => ['categories' => [['slug' => 'general']]],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importUnifiedFile($json, dryRun: false);

        self::assertNotEmpty($result->warnings, 'Must warn when no registry is available');
        self::assertStringContainsString('No import registry', $result->warnings[0]);
    }

    #[Test]
    public function importUnifiedFileWarnsWhenProviderNotRegistered(): void
    {
        $registry = new ImportExportRegistry();
        $service = $this->buildService($registry);

        $json = json_encode([
            'forum' => ['categories' => [['slug' => 'general']]],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importUnifiedFile($json, dryRun: false);

        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('No import provider', $result->warnings[0]);
    }

    #[Test]
    public function extensionSectionsConstantIncludesForum(): void
    {
        $reflection = new ReflectionClass(ImportExportService::class);
        /** @var list<string> $constant */
        $constant = $reflection->getConstant('EXTENSION_SECTIONS');

        self::assertIsArray($constant);
        self::assertContains('forum', $constant, 'EXTENSION_SECTIONS must include "forum"');
    }

    private function buildService(?ImportExportRegistry $registry): ImportExportService
    {
        // Build a real ImportParser with stub collaborators.
        // The importUnifiedFile method calls importParser->importBundle() only for
        // CMS sections (content, taxonomies, etc.), not for extension sections like forum.
        $importParser = new ImportParser(
            contentRepository: $this->createStub(ContentRepositoryInterface::class),
            translationRepository: $this->createStub(ContentTranslationRepositoryInterface::class),
            blockRepository: $this->createStub(ContentBlockRepositoryInterface::class),
            taxonomyRepository: $this->createStub(TaxonomyRepositoryInterface::class),
            menuRepository: $this->createStub(MenuRepositoryInterface::class),
            settingsService: $this->createStub(SettingsServiceInterface::class),
            config: ImportConfig::fromArray([]),
            auditLogger: null,
        );

        // ExportBundleGenerator and SiteDefinitionParser won't be called
        // for importUnifiedFile with forum-only data, so we use
        // newInstanceWithoutConstructor to avoid their heavy dependencies.
        $exportGenReflection = new ReflectionClass(ExportBundleGenerator::class);
        /** @var ExportBundleGenerator $exportGenerator */
        $exportGenerator = $exportGenReflection->newInstanceWithoutConstructor();

        $siteDefReflection = new ReflectionClass(SiteDefinitionParser::class);
        /** @var SiteDefinitionParser $siteDefParser */
        $siteDefParser = $siteDefReflection->newInstanceWithoutConstructor();

        return new ImportExportService($exportGenerator, $importParser, $siteDefParser, $registry);
    }
}
