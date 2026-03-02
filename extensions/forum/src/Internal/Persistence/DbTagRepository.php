<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;

#[Internal(reason: 'Raw-DB repository; use TagRepositoryInterface for public API')]
final readonly class DbTagRepository implements TagRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT t.*
        FROM forum_tags t
        WHERE t.id = :id
        SQL;

    private const string SQL_FIND_BY_SLUG = <<<'SQL'
        SELECT t.*
        FROM forum_tags t
        WHERE t.slug = :slug
        SQL;

    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT t.*
        FROM forum_tags t
        ORDER BY t.usage_count DESC, t.name ASC
        LIMIT 1000
        SQL;

    private const string SQL_FIND_BY_THREAD = <<<'SQL'
        SELECT t.*
        FROM forum_tags t
        INNER JOIN forum_thread_tags tt ON tt.tag_id = t.id
        WHERE tt.thread_id = :thread_id
        ORDER BY t.name ASC
        LIMIT 1000
        SQL;

    private const string SQL_ATTACH_PG = <<<'SQL'
        INSERT INTO forum_thread_tags (tag_id, thread_id)
        VALUES (:tag_id, :thread_id)
        ON CONFLICT (tag_id, thread_id) DO NOTHING
        SQL;

    private const string SQL_ATTACH_MYSQL = <<<'SQL'
        INSERT IGNORE INTO forum_thread_tags (tag_id, thread_id)
        VALUES (:tag_id, :thread_id)
        SQL;

    private const string SQL_DETACH_FROM_THREAD = <<<'SQL'
        DELETE FROM forum_thread_tags
        WHERE tag_id = :tag_id AND thread_id = :thread_id
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'slug', 'name', 'description', 'usage_count',
    ];

    private const array UPSERT_UPDATE = [
        'slug', 'name', 'description', 'usage_count',
    ];

    private const string SQL_FIND_POPULAR = <<<'SQL'
        SELECT t.*
        FROM forum_tags t
        ORDER BY t.usage_count DESC, t.name ASC
        LIMIT :limit
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_tags WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?Tag
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findBySlug(string $slug): ?Tag
    {
        $result = $this->connection->query(self::SQL_FIND_BY_SLUG, ['slug' => $slug]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findAll(): array
    {
        $result = $this->connection->query(self::SQL_FIND_ALL);

        return $result->map(self::hydrate(...));
    }

    public function findByThread(string $threadId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_THREAD, [
            'thread_id' => $threadId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function attachToThread(string $tagId, string $threadId): void
    {
        $sql = match ($this->connection->driver()) {
            Driver::MySQL => self::SQL_ATTACH_MYSQL,
            Driver::PostgreSQL, Driver::SQLite => self::SQL_ATTACH_PG,
        };

        $this->connection->execute($sql, [
            'tag_id' => $tagId,
            'thread_id' => $threadId,
        ]);
    }

    public function detachFromThread(string $tagId, string $threadId): void
    {
        $this->connection->execute(self::SQL_DETACH_FROM_THREAD, [
            'tag_id' => $tagId,
            'thread_id' => $threadId,
        ]);
    }

    public function findPopular(int $limit = 20): array
    {
        $result = $this->connection->query(self::SQL_FIND_POPULAR, [
            'limit' => $limit,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function save(Tag $tag): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_tags',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $tag->id,
            'slug' => $tag->slug,
            'name' => $tag->name,
            'description' => $tag->description,
            'usage_count' => $tag->usageCount,
        ]);
    }

    public function delete(Tag $tag): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $tag->id]);
    }

    private static function hydrate(Row $row): Tag
    {
        return new Tag(
            id: $row->getString('id'),
            slug: $row->getString('slug'),
            name: $row->getString('name'),
            description: $row->getNullableString('description') ?? '',
            usageCount: $row->getInt('usage_count'),
        );
    }
}
