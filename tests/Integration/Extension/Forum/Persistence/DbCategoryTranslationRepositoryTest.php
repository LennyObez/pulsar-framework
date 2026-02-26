<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Category\CategoryTranslation;
use Pulsar\Extension\Forum\Internal\Persistence\DbCategoryTranslationRepository;

#[CoversClass(DbCategoryTranslationRepository::class)]
final class DbCategoryTranslationRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbCategoryTranslationRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_category_translations (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                category_id VARCHAR(36) NOT NULL,
                locale VARCHAR(10) NOT NULL,
                name VARCHAR(200) NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT ''
            )
            SQL);

        $this->repository = new DbCategoryTranslationRepository($this->connection);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $translation = CategoryTranslation::create(
            id: 'tr-001',
            categoryId: 'cat-001',
            locale: 'en',
            name: 'General',
            description: 'General discussion',
        );
        $this->repository->save($translation);

        $found = $this->repository->findById('tr-001');

        self::assertNotNull($found);
        self::assertSame('tr-001', $found->id);
        self::assertSame('cat-001', $found->categoryId);
        self::assertSame('en', $found->locale);
        self::assertSame('General', $found->name);
        self::assertSame('General discussion', $found->description);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByCategoryAndLocale(): void
    {
        $translation = CategoryTranslation::create(
            id: 'tr-loc',
            categoryId: 'cat-loc',
            locale: 'fr',
            name: 'Général',
            description: 'Discussion générale',
        );
        $this->repository->save($translation);

        $found = $this->repository->findByCategoryAndLocale('cat-loc', 'fr');

        self::assertNotNull($found);
        self::assertSame('tr-loc', $found->id);
        self::assertSame('Général', $found->name);
    }

    #[Test]
    public function findByCategoryAndLocaleReturnsNullWhenNotFound(): void
    {
        $translation = CategoryTranslation::create(
            id: 'tr-no',
            categoryId: 'cat-no',
            locale: 'en',
            name: 'English',
        );
        $this->repository->save($translation);

        self::assertNull($this->repository->findByCategoryAndLocale('cat-no', 'de'));
    }

    #[Test]
    public function findByCategory(): void
    {
        $en = CategoryTranslation::create(
            id: 'tr-cat-en',
            categoryId: 'cat-multi',
            locale: 'en',
            name: 'General',
        );
        $fr = CategoryTranslation::create(
            id: 'tr-cat-fr',
            categoryId: 'cat-multi',
            locale: 'fr',
            name: 'Général',
        );
        $de = CategoryTranslation::create(
            id: 'tr-cat-de',
            categoryId: 'cat-multi',
            locale: 'de',
            name: 'Allgemein',
        );
        $this->repository->save($en);
        $this->repository->save($fr);
        $this->repository->save($de);

        $other = CategoryTranslation::create(
            id: 'tr-other',
            categoryId: 'cat-other',
            locale: 'en',
            name: 'Other',
        );
        $this->repository->save($other);

        $results = $this->repository->findByCategory('cat-multi');

        self::assertCount(3, $results);
        // Ordered by locale ASC
        self::assertSame('de', $results[0]->locale);
        self::assertSame('en', $results[1]->locale);
        self::assertSame('fr', $results[2]->locale);
    }

    #[Test]
    public function findByCategoryReturnsEmptyForUnknownCategory(): void
    {
        self::assertSame([], $this->repository->findByCategory('nonexistent'));
    }

    #[Test]
    public function saveUpdatesExistingTranslation(): void
    {
        $translation = CategoryTranslation::create(
            id: 'tr-upd',
            categoryId: 'cat-upd',
            locale: 'en',
            name: 'Original',
            description: 'Original description',
        );
        $this->repository->save($translation);

        $updated = $translation->update('Updated Name', 'Updated description');
        $this->repository->save($updated);

        $found = $this->repository->findById('tr-upd');
        self::assertNotNull($found);
        self::assertSame('Updated Name', $found->name);
        self::assertSame('Updated description', $found->description);
    }

    #[Test]
    public function deleteRemovesTranslation(): void
    {
        $translation = CategoryTranslation::create(
            id: 'tr-del',
            categoryId: 'cat-del',
            locale: 'en',
            name: 'To Delete',
        );
        $this->repository->save($translation);

        $this->repository->delete($translation);

        self::assertNull($this->repository->findById('tr-del'));
    }

    #[Test]
    public function multipleLocalesForSameCategory(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $locales = ['en', 'fr', 'de', 'es', 'it'];
            $translation = CategoryTranslation::create(
                id: "tr-ml-{$i}",
                categoryId: 'cat-ml',
                locale: $locales[$i],
                name: "Name {$locales[$i]}",
            );
            $this->repository->save($translation);
        }

        $results = $this->repository->findByCategory('cat-ml');
        self::assertCount(5, $results);

        $specific = $this->repository->findByCategoryAndLocale('cat-ml', 'es');
        self::assertNotNull($specific);
        self::assertSame('Name es', $specific->name);
    }

    #[Test]
    public function emptyDescriptionDefaultsCorrectly(): void
    {
        $translation = CategoryTranslation::create(
            id: 'tr-empty',
            categoryId: 'cat-empty',
            locale: 'en',
            name: 'No Description',
        );
        $this->repository->save($translation);

        $found = $this->repository->findById('tr-empty');
        self::assertNotNull($found);
        self::assertSame('', $found->description);
    }
}
