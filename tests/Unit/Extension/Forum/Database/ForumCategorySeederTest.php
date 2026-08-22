<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslation;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Database\ForumCategorySeeder;

use function count;

#[CoversClass(ForumCategorySeeder::class)]
final class ForumCategorySeederTest extends TestCase
{
    #[Test]
    public function definitionsReturnsThirteenCategories(): void
    {
        $definitions = ForumCategorySeeder::definitions();

        self::assertCount(13, $definitions);
    }

    #[Test]
    public function definitionsContainAllExpectedSlugs(): void
    {
        $definitions = ForumCategorySeeder::definitions();
        $slugs = array_map(static fn(array $d): string => $d['slug'], $definitions);

        $expected = [
            'announcements',
            'general',
            'getting-started',
            'core',
            'extensions',
            'security',
            'performance',
            'compliance',
            'showcase',
            'feature-requests',
            'bug-reports',
            'contributing',
            'off-topic',
        ];

        foreach ($expected as $slug) {
            self::assertContains($slug, $slugs, "Missing expected slug: $slug");
        }
    }

    #[Test]
    public function eachDefinitionHasFourLocaleTranslations(): void
    {
        $definitions = ForumCategorySeeder::definitions();
        $expectedLocales = ['en', 'fr', 'nl', 'de'];

        foreach ($definitions as $definition) {
            $locales = array_keys($definition['translations']);
            self::assertSame($expectedLocales, $locales, "Category '{$definition['slug']}' missing expected locales");
        }
    }

    #[Test]
    public function eachTranslationHasNameAndDescription(): void
    {
        $definitions = ForumCategorySeeder::definitions();

        foreach ($definitions as $definition) {
            foreach ($definition['translations'] as $locale => $texts) {
                self::assertArrayHasKey('name', $texts, "Translation '{$definition['slug']}/$locale' missing name");
                self::assertArrayHasKey('description', $texts, "Translation '{$definition['slug']}/$locale' missing description");
                self::assertNotEmpty($texts['name'], "Translation '{$definition['slug']}/$locale' has empty name");
                self::assertNotEmpty($texts['description'], "Translation '{$definition['slug']}/$locale' has empty description");
            }
        }
    }

    #[Test]
    public function runSavesAllCategoriesViaRepository(): void
    {
        /** @var list<Category> $savedCategories */
        $savedCategories = [];
        $categoryRepo = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepo->expects(self::exactly(13))
            ->method('save')
            ->willReturnCallback(function (Category $category) use (&$savedCategories): void {
                $savedCategories[] = $category;
            });

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);

        $seeder = new ForumCategorySeeder($categoryRepo, $translationRepo);
        $connection = $this->createStub(ConnectionInterface::class);
        $seeder->run($connection);

        // Verify slugs match definitions
        $savedSlugs = array_map(static fn(Category $c): string => $c->slug, $savedCategories);
        $expectedSlugs = array_map(static fn(array $d): string => $d['slug'], ForumCategorySeeder::definitions());
        self::assertSame($expectedSlugs, $savedSlugs);
    }

    #[Test]
    public function runSavesTranslationsForEachCategory(): void
    {
        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);

        /** @var list<CategoryTranslation> $savedTranslations */
        $savedTranslations = [];
        $translationRepo = $this->createMock(CategoryTranslationRepositoryInterface::class);
        // 13 categories x 4 locales = 52 translations
        $translationRepo->expects(self::exactly(52))
            ->method('save')
            ->willReturnCallback(function (CategoryTranslation $t) use (&$savedTranslations): void {
                $savedTranslations[] = $t;
            });

        $seeder = new ForumCategorySeeder($categoryRepo, $translationRepo);
        $connection = $this->createStub(ConnectionInterface::class);
        $seeder->run($connection);

        // Verify each translation has a non-empty locale, name, and description
        foreach ($savedTranslations as $translation) {
            self::assertNotEmpty($translation->locale);
            self::assertNotEmpty($translation->name);
            self::assertNotEmpty($translation->categoryId);
        }
    }

    #[Test]
    public function runAssignsIncrementingSortOrder(): void
    {
        /** @var list<Category> $savedCategories */
        $savedCategories = [];
        $categoryRepo = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepo->expects(self::exactly(13))
            ->method('save')
            ->willReturnCallback(function (Category $c) use (&$savedCategories): void {
                $savedCategories[] = $c;
            });

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);

        $seeder = new ForumCategorySeeder($categoryRepo, $translationRepo);
        $connection = $this->createStub(ConnectionInterface::class);
        $seeder->run($connection);

        $sortOrders = array_map(static fn(Category $c): int => $c->sortOrder, $savedCategories);

        // Sort orders should match definition indices (0..12)
        for ($i = 0; $i < count($savedCategories); $i++) {
            self::assertSame($i, $sortOrders[$i], "Category at index $i has wrong sortOrder");
        }
    }

    #[Test]
    public function runCreatesCategoriesWithUniqueIds(): void
    {
        /** @var list<Category> $savedCategories */
        $savedCategories = [];
        $categoryRepo = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepo->expects(self::exactly(13))
            ->method('save')
            ->willReturnCallback(function (Category $c) use (&$savedCategories): void {
                $savedCategories[] = $c;
            });

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);

        $seeder = new ForumCategorySeeder($categoryRepo, $translationRepo);
        $connection = $this->createStub(ConnectionInterface::class);
        $seeder->run($connection);

        $ids = array_map(static fn(Category $c): string => $c->id, $savedCategories);
        $uniqueIds = array_unique($ids);
        self::assertCount(count($ids), $uniqueIds, 'Category IDs should be unique');
    }

    #[Test]
    public function categoriesAreTopLevelWithNoParent(): void
    {
        /** @var list<Category> $savedCategories */
        $savedCategories = [];
        $categoryRepo = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepo->expects(self::exactly(13))
            ->method('save')
            ->willReturnCallback(function (Category $c) use (&$savedCategories): void {
                $savedCategories[] = $c;
            });

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);

        $seeder = new ForumCategorySeeder($categoryRepo, $translationRepo);
        $connection = $this->createStub(ConnectionInterface::class);
        $seeder->run($connection);

        foreach ($savedCategories as $category) {
            self::assertNull($category->parentId, "Category '{$category->slug}' should be top-level");
        }
    }
}
