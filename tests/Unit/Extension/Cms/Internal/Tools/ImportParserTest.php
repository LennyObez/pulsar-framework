<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
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
final class ImportParserTest extends TestCase
{
    private ContentRepositoryInterface&MockObject $contentRepo;
    private ContentTranslationRepositoryInterface&MockObject $translationRepo;
    private ContentBlockRepositoryInterface&MockObject $blockRepo;
    private TaxonomyRepositoryInterface&MockObject $taxonomyRepo;
    private MenuRepositoryInterface&MockObject $menuRepo;
    private SettingsServiceInterface&MockObject $settingsService;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createMock(ContentRepositoryInterface::class);
        $this->translationRepo = $this->createMock(ContentTranslationRepositoryInterface::class);
        $this->blockRepo = $this->createMock(ContentBlockRepositoryInterface::class);
        $this->taxonomyRepo = $this->createMock(TaxonomyRepositoryInterface::class);
        $this->menuRepo = $this->createMock(MenuRepositoryInterface::class);
        $this->settingsService = $this->createMock(SettingsServiceInterface::class);
    }

    private function createParser(?ImportConfig $config = null): ImportParser
    {
        return new ImportParser(
            $this->contentRepo,
            $this->translationRepo,
            $this->blockRepo,
            $this->taxonomyRepo,
            $this->menuRepo,
            $this->settingsService,
            $config ?? new ImportConfig(duplicatePolicy: DuplicateResolutionPolicy::Skip),
            $this->createStub(AuditLoggerInterface::class),
        );
    }

    // --- Issue 4: template passed to Content::create() ---

    #[Test]
    public function flatImportPassesTemplateToContentCreate(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);

        /** @var list<Content> $savedContents */
        $savedContents = [];
        $this->contentRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (Content $content) use (&$savedContents): void {
                $savedContents[] = $content;
            });

        $this->translationRepo->method('save')->willReturnCallback(function (): void {});

        $json = json_encode([
            'content' => [
                [
                    'slug' => 'landing-page',
                    'title' => 'Landing',
                    'content_type' => 'page',
                    'template' => 'cms::public.pages.landing',
                    'body' => 'Hello world',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $result = $parser->importBundle($json, false);

        self::assertSame(['content' => 1], $result->created);
        self::assertCount(1, $savedContents);
        self::assertSame('cms::public.pages.landing', $savedContents[0]->template);
    }

    #[Test]
    public function multilocaleImportPassesTemplateToContentCreate(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);

        /** @var list<Content> $savedContents */
        $savedContents = [];
        $this->contentRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (Content $content) use (&$savedContents): void {
                $savedContents[] = $content;
            });

        $this->translationRepo->method('save')->willReturnCallback(function (): void {});

        $json = json_encode([
            'content' => [
                [
                    'content_type' => 'article',
                    'template' => 'article-wide',
                    'translations' => [
                        'en' => ['title' => 'English Title', 'slug_segment' => 'hello', 'body' => 'Body'],
                        'fr' => ['title' => 'Titre Francais', 'slug_segment' => 'bonjour', 'body' => 'Corps'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $result = $parser->importBundle($json, false);

        self::assertSame(['content' => 1], $result->created);
        self::assertCount(1, $savedContents);
        self::assertSame('article-wide', $savedContents[0]->template);
    }

    #[Test]
    public function templateDefaultsToNullWhenMissing(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);

        /** @var list<Content> $savedContents */
        $savedContents = [];
        $this->contentRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (Content $content) use (&$savedContents): void {
                $savedContents[] = $content;
            });

        $this->translationRepo->method('save')->willReturnCallback(function (): void {});

        $json = json_encode([
            'content' => [
                [
                    'slug' => 'no-template',
                    'title' => 'No Template',
                    'body' => 'Default rendering',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $result = $parser->importBundle($json, false);

        self::assertSame(['content' => 1], $result->created);
        self::assertNull($savedContents[0]->template);
    }

    // --- Issue 5: slug_segment vs slug handled by ImportFieldResolverTrait ---

    #[Test]
    public function flatImportResolvesSlugFromSlugSegmentField(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);
        $this->contentRepo->method('save')->willReturnCallback(function (): void {});

        /** @var list<ContentTranslation> $savedTranslations */
        $savedTranslations = [];
        $this->translationRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (ContentTranslation $t) use (&$savedTranslations): void {
                $savedTranslations[] = $t;
            });

        $json = json_encode([
            'content' => [
                [
                    'slug_segment' => 'about-us',
                    'title' => 'About Us',
                    'body' => 'We are ...',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $parser->importBundle($json, false);

        self::assertCount(1, $savedTranslations);
        self::assertSame('about-us', $savedTranslations[0]->slugSegment);
    }

    #[Test]
    public function flatImportPrefersSlugOverSlugSegment(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);
        $this->contentRepo->method('save')->willReturnCallback(function (): void {});

        /** @var list<ContentTranslation> $savedTranslations */
        $savedTranslations = [];
        $this->translationRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (ContentTranslation $t) use (&$savedTranslations): void {
                $savedTranslations[] = $t;
            });

        $json = json_encode([
            'content' => [
                [
                    'slug' => 'preferred',
                    'slug_segment' => 'fallback',
                    'title' => 'Slug Priority',
                    'body' => 'Testing slug priority',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $parser->importBundle($json, false);

        self::assertCount(1, $savedTranslations);
        self::assertSame('preferred', $savedTranslations[0]->slugSegment);
    }

    // --- Issue 6: processSettings flat array format ---

    #[Test]
    public function processesGroupedSettingsFormat(): void
    {
        $this->settingsService->method('get')->willReturn(null);

        $setCalls = [];
        $this->settingsService->expects(self::exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $group, string $key, mixed $value) use (&$setCalls): void {
                $setCalls[] = ['group' => $group, 'key' => $key, 'value' => $value];
            });

        $json = json_encode([
            'settings' => [
                'general' => [
                    'site_name' => 'My Site',
                    'tagline' => 'A tagline',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $result = $parser->importBundle($json, false);

        self::assertSame(['settings' => 2], $result->created);
        self::assertSame('general', $setCalls[0]['group']);
        self::assertSame('site_name', $setCalls[0]['key']);
    }

    #[Test]
    public function processesFlatSettingsFormatAsGeneralGroup(): void
    {
        $this->settingsService->method('get')->willReturn(null);

        $setCalls = [];
        $this->settingsService->expects(self::exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $group, string $key, mixed $value) use (&$setCalls): void {
                $setCalls[] = ['group' => $group, 'key' => $key, 'value' => $value];
            });

        $json = json_encode([
            'settings' => [
                'site_name' => 'My Site',
                'tagline' => 'A tagline',
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $result = $parser->importBundle($json, false);

        self::assertSame(['settings' => 2], $result->created);

        // Flat format should be wrapped in "general" group
        self::assertSame('general', $setCalls[0]['group']);
        self::assertSame('site_name', $setCalls[0]['key']);
        self::assertSame('My Site', $setCalls[0]['value']);
    }

    #[Test]
    public function settingsUpdateCountsExistingKeys(): void
    {
        $this->settingsService->method('get')->willReturn('existing-value');
        $this->settingsService->method('set')->willReturnCallback(function (): void {});

        $json = json_encode([
            'settings' => [
                'general' => [
                    'site_name' => 'Updated',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $result = $parser->importBundle($json, false);

        self::assertSame(['settings' => 1], $result->updated);
    }

    // --- General import behavior ---

    #[Test]
    public function rejectsInvalidJson(): void
    {
        $parser = $this->createParser();
        $result = $parser->importBundle('{invalid', false);

        self::assertNotEmpty($result->errors);
        self::assertStringContainsString('Invalid JSON', $result->errors[0]);
    }

    #[Test]
    public function rejectsOversizedPayload(): void
    {
        $parser = $this->createParser(new ImportConfig(maxImportSizeBytes: 10));
        $result = $parser->importBundle('{"content": []}', false);

        self::assertNotEmpty($result->errors);
        self::assertStringContainsString('exceeds maximum size', $result->errors[0]);
    }

    #[Test]
    public function dryRunDoesNotPersist(): void
    {
        $this->contentRepo->method('findByPath')->willReturn(null);
        $this->contentRepo->expects(self::never())->method('save');
        $this->translationRepo->expects(self::never())->method('save');

        $json = json_encode([
            'content' => [
                [
                    'slug' => 'dry-run-test',
                    'title' => 'Dry Run',
                    'body' => 'Should not persist',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $result = $parser->importBundle($json, true);

        self::assertTrue($result->dryRun);
        self::assertSame(['content' => 1], $result->created);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function mixedSettingsFormatProvider(): iterable
    {
        yield 'grouped format' => [['branding' => ['logo' => 'logo.png']]];
        yield 'flat format' => [['logo' => 'logo.png']];
    }

    #[Test]
    #[DataProvider('mixedSettingsFormatProvider')]
    public function settingsImportHandlesBothFormats(array $settingsPayload): void
    {
        $this->settingsService->method('get')->willReturn(null);
        $this->settingsService->method('set')->willReturnCallback(function (): void {});

        $json = json_encode(['settings' => $settingsPayload], JSON_THROW_ON_ERROR);

        $parser = $this->createParser();
        $result = $parser->importBundle($json, false);

        self::assertArrayHasKey('settings', $result->created);
        self::assertGreaterThan(0, $result->created['settings']);
    }
}
