<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Tools\ImportParser;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Tools\DuplicateResolutionPolicy;
use Pulsar\Extension\Cms\Tools\ImportConfig;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ImportParser::class)]
final class ImportParserSlugTest extends TestCase
{
    #[Test]
    public function contentWithEmptySlugSegmentIsNotSkipped(): void
    {
        // Arrange: homepage content with empty slug_segment (valid for root page)
        $parser = $this->createParser();

        $bundle = json_encode([
            'content' => [
                [
                    'translations' => [
                        'en' => [
                            'title' => 'Homepage',
                            'slug_segment' => '',
                            'path' => '',
                            'body' => '<p>Welcome</p>',
                        ],
                    ],
                    'content_type' => 'page',
                    'status' => 'published',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $result = $parser->importBundle($bundle, dryRun: true);

        // Assert: the content should be counted as created, not skipped
        $totalSkipped = $result->totalSkipped();
        $totalCreated = $result->totalCreated();

        self::assertSame(0, $totalSkipped, 'Homepage with empty slug_segment must not be skipped');
        self::assertSame(1, $totalCreated, 'Homepage with empty slug_segment must be counted as created');
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function contentWithNullSlugAndNoImportIdIsSkippedWithWarning(): void
    {
        // Arrange: content with no slug_segment, no slug, and no import_id
        $parser = $this->createParser();

        $bundle = json_encode([
            'content' => [
                [
                    'translations' => [
                        'en' => [
                            'title' => 'No Slug Page',
                            'body' => '<p>Missing slug</p>',
                            // No slug_segment, no slug, no path
                        ],
                    ],
                    'content_type' => 'page',
                    'status' => 'published',
                    // No import_id
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $result = $parser->importBundle($bundle, dryRun: true);

        // Assert: should be skipped with a warning
        self::assertSame(1, $result->totalSkipped(), 'Content without slug_segment and import_id must be skipped');
        self::assertSame(0, $result->totalCreated());
        self::assertNotEmpty($result->warnings, 'A warning must be emitted when skipping due to missing slug');
        self::assertStringContainsString(
            'missing slug_segment and import_id',
            $result->warnings[0],
        );
    }

    #[Test]
    public function contentWithSlugFieldIsAccepted(): void
    {
        // Arrange: content using 'slug' key (alias for slug_segment)
        $parser = $this->createParser();

        $bundle = json_encode([
            'content' => [
                [
                    'translations' => [
                        'en' => [
                            'title' => 'About',
                            'slug' => 'about',
                            'path' => 'about',
                            'body' => '<p>About us</p>',
                        ],
                    ],
                    'content_type' => 'page',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $result = $parser->importBundle($bundle, dryRun: true);

        // Assert
        self::assertSame(1, $result->totalCreated());
        self::assertSame(0, $result->totalSkipped());
    }

    #[Test]
    public function contentWithImportIdFallsBackToItAsSlug(): void
    {
        // Arrange: no slug fields, but has import_id
        $parser = $this->createParser();

        $bundle = json_encode([
            'content' => [
                [
                    'translations' => [
                        'en' => [
                            'title' => 'Fallback Page',
                            'body' => '<p>Uses import_id</p>',
                        ],
                    ],
                    'content_type' => 'page',
                    'import_id' => 'legacy-page-42',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $result = $parser->importBundle($bundle, dryRun: true);

        // Assert: import_id is used as slug fallback, so not skipped
        self::assertSame(1, $result->totalCreated());
        self::assertSame(0, $result->totalSkipped());
    }

    private function createParser(): ImportParser
    {
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $contentRepo->method('findByPath')->willReturn(null);
        $contentRepo->method('findByImportId')->willReturn(null);

        return new ImportParser(
            contentRepository: $contentRepo,
            translationRepository: $this->createStub(ContentTranslationRepositoryInterface::class),
            blockRepository: $this->createStub(ContentBlockRepositoryInterface::class),
            taxonomyRepository: $this->createStub(TaxonomyRepositoryInterface::class),
            menuRepository: $this->createStub(MenuRepositoryInterface::class),
            settingsService: $this->createStub(SettingsServiceInterface::class),
            config: new ImportConfig(
                duplicatePolicy: DuplicateResolutionPolicy::Skip,
            ),
            auditLogger: $this->createStub(AuditLoggerInterface::class),
        );
    }
}
