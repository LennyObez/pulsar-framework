<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Category\CategoryTranslation;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;

#[Internal(reason: 'Raw-DB repository — use CategoryTranslationRepositoryInterface for public API')]
final readonly class DbCategoryTranslationRepository implements CategoryTranslationRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT t.*
        FROM forum_category_translations t
        WHERE t.id = :id
        SQL;

    private const string SQL_FIND_BY_CATEGORY_AND_LOCALE = <<<'SQL'
        SELECT t.*
        FROM forum_category_translations t
        WHERE t.category_id = :category_id AND t.locale = :locale
        SQL;

    private const string SQL_FIND_BY_CATEGORY = <<<'SQL'
        SELECT t.*
        FROM forum_category_translations t
        WHERE t.category_id = :category_id
        ORDER BY t.locale ASC
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'category_id', 'locale', 'name', 'description',
    ];

    private const array UPSERT_UPDATE = ['name', 'description'];

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_category_translations WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?CategoryTranslation
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByCategoryAndLocale(string $categoryId, string $locale): ?CategoryTranslation
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CATEGORY_AND_LOCALE, [
            'category_id' => $categoryId,
            'locale' => $locale,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByCategory(string $categoryId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CATEGORY, [
            'category_id' => $categoryId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function save(CategoryTranslation $translation): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_category_translations',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $translation->id,
            'category_id' => $translation->categoryId,
            'locale' => $translation->locale,
            'name' => $translation->name,
            'description' => $translation->description,
        ]);
    }

    public function delete(CategoryTranslation $translation): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $translation->id]);
    }

    private static function hydrate(Row $row): CategoryTranslation
    {
        return new CategoryTranslation(
            id: $row->getString('id'),
            categoryId: $row->getString('category_id'),
            locale: $row->getString('locale'),
            name: $row->getString('name'),
            description: $row->getString('description'),
        );
    }
}
