<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Tools\ImportParser;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Navigation\MenuTranslation;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Tools\ImportConfig;

use function count;
use function is_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Tests menu import logic in ImportParser, covering flat and multilingual formats
 * and the slug-as-location fallback.
 */
#[CoversClass(ImportParser::class)]
final class ImportParserMenuTest extends TestCase
{
    private ContentRepositoryInterface&Stub $contentRepo;
    private ContentTranslationRepositoryInterface&Stub $translationRepo;
    private ContentBlockRepositoryInterface&Stub $blockRepo;
    private TaxonomyRepositoryInterface&Stub $taxonomyRepo;
    private SettingsServiceInterface&Stub $settingsService;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $this->blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);
        $this->taxonomyRepo = $this->createStub(TaxonomyRepositoryInterface::class);
        $this->settingsService = $this->createStub(SettingsServiceInterface::class);
    }

    private function createParser(
        MenuRepositoryInterface|null $menuRepo = null,
        ?ImportConfig $config = null,
    ): ImportParser {
        return new ImportParser(
            $this->contentRepo,
            $this->translationRepo,
            $this->blockRepo,
            $this->taxonomyRepo,
            $menuRepo ?? $this->createStub(MenuRepositoryInterface::class),
            $this->settingsService,
            $config ?? new ImportConfig(),
            null,
        );
    }

    #[Test]
    public function importMenuWithFlatFormat(): void
    {
        $menuRepo = $this->createMock(MenuRepositoryInterface::class);
        $menuRepo->method('findByLocation')->willReturn(null);
        $menuRepo->expects(self::once())->method('save')
            ->with(
                self::callback(static fn(Menu $menu): bool => $menu->location === 'header'),
                self::callback(static function (array $translations): bool {
                    return count($translations) === 1
                        && $translations[0] instanceof MenuTranslation
                        && $translations[0]->name === 'Main Nav'
                        && $translations[0]->locale === 'en';
                }),
            );

        $parser = $this->createParser($menuRepo);
        $json = json_encode([
            'menus' => [
                [
                    'location' => 'header',
                    'locale' => 'en',
                    'name' => 'Main Nav',
                    'items' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $parser->importBundle($json, dryRun: false);

        self::assertSame(1, $result->created['menus']);
    }

    #[Test]
    public function importMenuWithMultilocaleTranslations(): void
    {
        $menuRepo = $this->createMock(MenuRepositoryInterface::class);
        $menuRepo->method('findByLocation')->willReturn(null);

        $savedTranslations = [];
        $menuRepo->expects(self::once())->method('save')
            ->with(
                self::callback(static fn(Menu $menu): bool => $menu->location === 'footer'),
                self::callback(static function (array $translations) use (&$savedTranslations): bool {
                    $savedTranslations = $translations;

                    return count($translations) === 2;
                }),
            );

        $parser = $this->createParser($menuRepo);
        $json = json_encode([
            'menus' => [
                [
                    'location' => 'footer',
                    'translations' => [
                        'en' => ['name' => 'Footer Nav'],
                        'fr' => ['name' => 'Nav Pied de page'],
                    ],
                    'items' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $parser->importBundle($json, dryRun: false);

        self::assertSame(1, $result->created['menus']);

        // Verify both locale translations were created
        self::assertCount(2, $savedTranslations);
        $locales = array_map(static fn(MenuTranslation $t): string => $t->locale, $savedTranslations);
        self::assertContains('en', $locales);
        self::assertContains('fr', $locales);
    }

    #[Test]
    public function importMenuUsesSlugAsLocationFallback(): void
    {
        $menuRepo = $this->createMock(MenuRepositoryInterface::class);
        $menuRepo->method('findByLocation')->willReturn(null);
        $menuRepo->expects(self::once())->method('save')
            ->with(
                self::callback(static fn(Menu $menu): bool => $menu->location === 'sidebar'),
                self::callback(static fn(mixed $translations): bool => is_array($translations)),
            );

        $parser = $this->createParser($menuRepo);
        $json = json_encode([
            'menus' => [
                [
                    'slug' => 'sidebar',
                    'name' => 'Sidebar Nav',
                    'items' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $parser->importBundle($json, dryRun: false);

        self::assertSame(1, $result->created['menus']);
    }

    #[Test]
    public function importMenuSkipsEntryWithMissingLocation(): void
    {
        $parser = $this->createParser();
        $json = json_encode([
            'menus' => [
                ['name' => 'No Location', 'items' => []],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $parser->importBundle($json, dryRun: false);

        self::assertSame(0, $result->created['menus'] ?? 0);
        self::assertSame(1, $result->skipped['menus']);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('missing location', $result->warnings[0]);
    }

    #[Test]
    public function importMenuDryRunCountsWithoutPersisting(): void
    {
        $menuRepo = $this->createMock(MenuRepositoryInterface::class);
        $menuRepo->method('findByLocation')->willReturn(null);
        $menuRepo->expects(self::never())->method('save');

        $parser = $this->createParser($menuRepo);
        $json = json_encode([
            'menus' => [
                ['location' => 'header', 'name' => 'Main', 'items' => []],
                ['location' => 'footer', 'name' => 'Footer', 'items' => []],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $parser->importBundle($json, dryRun: true);

        self::assertSame(2, $result->created['menus']);
    }

    #[Test]
    public function importMultilocaleMenuWithEmptyTranslationsIsSkipped(): void
    {
        $parser = $this->createParser();
        $json = json_encode([
            'menus' => [
                [
                    'location' => 'header',
                    'translations' => [],
                    'items' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $parser->importBundle($json, dryRun: false);

        self::assertSame(1, $result->skipped['menus']);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('empty translations', $result->warnings[0]);
    }

    #[Test]
    public function importMenuMultilocaleFiltersAllowedLocales(): void
    {
        $menuRepo = $this->createMock(MenuRepositoryInterface::class);
        $menuRepo->method('findByLocation')->willReturn(null);

        $savedTranslations = [];
        $menuRepo->expects(self::once())->method('save')
            ->with(
                self::isInstanceOf(Menu::class),
                self::callback(static function (array $translations) use (&$savedTranslations): bool {
                    $savedTranslations = $translations;

                    return true;
                }),
            );

        $config = new ImportConfig(allowedLocales: ['en']);
        $parser = $this->createParser($menuRepo, $config);
        $json = json_encode([
            'menus' => [
                [
                    'location' => 'header',
                    'translations' => [
                        'en' => ['name' => 'English Nav'],
                        'fr' => ['name' => 'Nav Francais'],
                        'de' => ['name' => 'Deutsche Nav'],
                    ],
                    'items' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $parser->importBundle($json, dryRun: false);

        self::assertSame(1, $result->created['menus']);
        // Only 'en' should be saved since allowedLocales restricts to ['en']
        self::assertCount(1, $savedTranslations);
        self::assertSame('en', $savedTranslations[0]->locale);
    }

    #[Test]
    public function importMenuWithItemsCreatesMenuItems(): void
    {
        $menuRepo = $this->createMock(MenuRepositoryInterface::class);
        $menuRepo->method('findByLocation')->willReturn(null);
        $menuRepo->expects(self::once())->method('save');
        $menuRepo->expects(self::exactly(2))->method('saveItem');

        $parser = $this->createParser($menuRepo);
        $json = json_encode([
            'menus' => [
                [
                    'location' => 'header',
                    'name' => 'Main',
                    'items' => [
                        ['label' => 'Home', 'url' => '/'],
                        ['label' => 'About', 'url' => '/about'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $parser->importBundle($json, dryRun: false);

        self::assertSame(1, $result->created['menus']);
    }
}
