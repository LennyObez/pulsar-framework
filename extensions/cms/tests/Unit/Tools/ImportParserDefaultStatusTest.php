<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Tools\ImportParser;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Tools\ImportConfig;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies that imported content defaults to published status (blocker #6).
 */
final class ImportParserDefaultStatusTest extends TestCase
{
    #[Test]
    public function importedContentIsPublishedByDefault(): void
    {
        $savedContent = null;

        $contentRepo = $this->createMock(ContentRepositoryInterface::class);
        $contentRepo->expects($this->atLeastOnce())
            ->method('save')
            ->willReturnCallback(function (Content $content) use (&$savedContent): void {
                $savedContent = $content;
            });

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByPath')->willReturn(null);

        $parser = new ImportParser(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $this->createStub(ContentBlockRepositoryInterface::class),
            taxonomyRepository: $this->createStub(TaxonomyRepositoryInterface::class),
            menuRepository: $this->createStub(MenuRepositoryInterface::class),
            settingsService: $this->createStub(SettingsServiceInterface::class),
            config: ImportConfig::fromArray([]),
            auditLogger: null,
        );

        // Import a content item with NO explicit status field
        $json = json_encode([
            'content' => [
                [
                    'title' => 'Welcome Page',
                    'slug' => 'welcome-page',
                    'body' => '<p>Hello world</p>',
                    'content_type' => 'page',
                    // No 'status' key -- should default to published
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser->importBundle($json, dryRun: false);

        self::assertNotNull($savedContent, 'Content must have been saved');
        self::assertTrue($savedContent->isPublished(), 'Imported content without explicit status must default to published');
    }

    #[Test]
    public function importedContentRespectsExplicitDraftStatus(): void
    {
        $savedContent = null;

        $contentRepo = $this->createMock(ContentRepositoryInterface::class);
        $contentRepo->expects($this->atLeastOnce())
            ->method('save')
            ->willReturnCallback(function (Content $content) use (&$savedContent): void {
                $savedContent = $content;
            });

        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $translationRepo->method('findByPath')->willReturn(null);

        $parser = new ImportParser(
            contentRepository: $contentRepo,
            translationRepository: $translationRepo,
            blockRepository: $this->createStub(ContentBlockRepositoryInterface::class),
            taxonomyRepository: $this->createStub(TaxonomyRepositoryInterface::class),
            menuRepository: $this->createStub(MenuRepositoryInterface::class),
            settingsService: $this->createStub(SettingsServiceInterface::class),
            config: ImportConfig::fromArray([]),
            auditLogger: null,
        );

        // Import a content item with explicit 'draft' status
        $json = json_encode([
            'content' => [
                [
                    'title' => 'Draft Page',
                    'slug' => 'draft-page',
                    'body' => '<p>Not ready</p>',
                    'content_type' => 'page',
                    'status' => 'draft',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser->importBundle($json, dryRun: false);

        self::assertNotNull($savedContent, 'Content must have been saved');
        self::assertTrue($savedContent->isDraft(), 'Content with explicit draft status must remain draft');
    }
}
