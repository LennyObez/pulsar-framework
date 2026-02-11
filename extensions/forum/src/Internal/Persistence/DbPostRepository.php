<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;

use function ceil;
use function max;
use function min;

#[Internal(reason: 'Raw-DB repository — use PostRepositoryInterface for public API')]
final readonly class DbPostRepository implements PostRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT p.*
        FROM forum_posts p
        WHERE p.id = :id AND p.deleted_at IS NULL
            AND COALESCE(p.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_COUNT_BY_THREAD = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_posts p
        WHERE p.thread_id = :thread_id AND p.deleted_at IS NULL
            AND COALESCE(p.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_FIND_BY_THREAD = <<<'SQL'
        SELECT p.*
        FROM forum_posts p
        WHERE p.thread_id = :thread_id AND p.deleted_at IS NULL
            AND COALESCE(p.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        ORDER BY p.created_at ASC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_COUNT_BY_AUTHOR = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_posts p
        WHERE p.author_id = :author_id AND p.deleted_at IS NULL
            AND COALESCE(p.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_FIND_BY_AUTHOR = <<<'SQL'
        SELECT p.*
        FROM forum_posts p
        WHERE p.author_id = :author_id AND p.deleted_at IS NULL
            AND COALESCE(p.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'thread_id', 'parent_id', 'author_id',
        'body', 'body_html', 'is_solution', 'vote_score',
        'edit_count', 'edited_by', 'ip_hash', 'user_agent_hash',
        'edited_at', 'edit_window_expires_at',
        'created_at', 'updated_at', 'deleted_at', 'version',
    ];

    private const array UPSERT_UPDATE = [
        'body', 'body_html', 'is_solution', 'vote_score',
        'edit_count', 'edited_by', 'edited_at', 'edit_window_expires_at',
        'updated_at', 'deleted_at',
    ];

    private const string SQL_INCREMENT_VOTE_SCORE = <<<'SQL'
        UPDATE forum_posts
        SET vote_score = vote_score + :delta,
            updated_at = :updated_at
        WHERE id = :id AND deleted_at IS NULL
        SQL;

    private const string SQL_SOFT_DELETE = <<<'SQL'
        UPDATE forum_posts
        SET deleted_at = :deleted_at, updated_at = :updated_at
        WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?Post
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id, 'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByThread(
        string $threadId,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_THREAD, [
            'thread_id' => $threadId,
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_THREAD, [
            'thread_id' => $threadId,
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function findByAuthor(
        string $authorId,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_AUTHOR, [
            'author_id' => $authorId,
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_AUTHOR, [
            'author_id' => $authorId,
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function countByThread(string $threadId): int
    {
        $result = $this->connection->query(self::SQL_COUNT_BY_THREAD, [
            'thread_id' => $threadId,
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ]);

        return $result->first()?->getInt('total') ?? 0;
    }

    public function save(Post $post): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_posts',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
            extraWhere: 'forum_posts.version = :expected_version',
            extraSet: 'version = forum_posts.version + 1',
        );

        $affected = $this->connection->execute($sql, [
            'id' => $post->id,
            'tenant_id' => $post->tenantId,
            'thread_id' => $post->threadId,
            'parent_id' => $post->parentId,
            'author_id' => $post->authorId,
            'body' => $post->body,
            'body_html' => $post->bodyHtml,
            'is_solution' => $post->isSolution,
            'vote_score' => $post->voteScore,
            'edit_count' => $post->editCount,
            'edited_by' => $post->editedBy,
            'ip_hash' => $post->ipHash,
            'user_agent_hash' => $post->userAgentHash,
            'edited_at' => $post->editedAt?->format('c'),
            'edit_window_expires_at' => $post->editWindowExpiresAt?->format('c'),
            'created_at' => $post->createdAt->format('c'),
            'updated_at' => $post->updatedAt->format('c'),
            'deleted_at' => $post->deletedAt?->format('c'),
            'version' => $post->version,
            'expected_version' => $post->version,
        ]);

        if ($affected === 0) {
            throw ForumException::concurrencyConflict($post->id, $post->version);
        }
    }

    public function delete(Post $post): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_SOFT_DELETE, [
            'id' => $post->id,
            'deleted_at' => $now->format('c'),
            'updated_at' => $now->format('c'),
        ]);
    }

    public function incrementVoteScore(string $id, int $delta): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_INCREMENT_VOTE_SCORE, [
            'id' => $id,
            'delta' => $delta,
            'updated_at' => $now->format('c'),
        ]);
    }

    private static function hydrate(Row $row): Post
    {
        return new Post(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            threadId: $row->getString('thread_id'),
            parentId: $row->getNullableString('parent_id'),
            authorId: $row->getString('author_id'),
            body: $row->getString('body'),
            bodyHtml: $row->getString('body_html'),
            isSolution: $row->getBool('is_solution'),
            voteScore: $row->getInt('vote_score'),
            editCount: $row->getInt('edit_count'),
            editedBy: $row->getNullableString('edited_by'),
            ipHash: $row->getString('ip_hash'),
            userAgentHash: $row->getString('user_agent_hash'),
            editedAt: self::toDateTime($row->getNullableString('edited_at')),
            editWindowExpiresAt: self::toDateTime($row->getNullableString('edit_window_expires_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
            deletedAt: self::toDateTime($row->getNullableString('deleted_at')),
            version: $row->getInt('version'),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
