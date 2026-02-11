<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

use function ceil;
use function max;
use function min;

#[Internal(reason: 'Raw-DB repository — use ThreadRepositoryInterface for public API')]
final readonly class DbThreadRepository implements ThreadRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.id = :id
            AND COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_SLUG = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.slug = :slug
            AND COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_COUNT_BY_CATEGORY = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_threads t
        WHERE t.category_id = :category_id
            AND COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_CATEGORY = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.category_id = :category_id
            AND COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_COUNT_BY_AUTHOR = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_threads t
        WHERE t.author_id = :author_id
            AND COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_AUTHOR = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.author_id = :author_id
            AND COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        ORDER BY t.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_COUNT_BY_TAG = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_threads t
        INNER JOIN forum_thread_tags tt ON tt.thread_id = t.id
        WHERE tt.tag_id = :tag_id
            AND COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_TAG = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        INNER JOIN forum_thread_tags tt ON tt.thread_id = t.id
        WHERE tt.tag_id = :tag_id
            AND COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        ORDER BY t.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_COUNT_RECENT = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_threads t
        WHERE COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_RECENT = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE COALESCE(t.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND t.deleted_at IS NULL
        ORDER BY t.is_pinned DESC, CASE WHEN t.last_activity_at IS NULL THEN 1 ELSE 0 END, t.last_activity_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'category_id', 'author_id', 'title', 'slug',
        'type', 'status', 'is_pinned', 'is_locked', 'solved_post_id',
        'reply_count', 'view_count', 'vote_score', 'last_activity_at',
        'ip_hash', 'user_agent_hash',
        'created_at', 'updated_at', 'deleted_at', 'version',
    ];

    private const array UPSERT_UPDATE = [
        'category_id', 'title', 'slug', 'type', 'status',
        'is_pinned', 'is_locked', 'solved_post_id',
        'reply_count', 'view_count', 'vote_score', 'last_activity_at',
        'updated_at', 'deleted_at',
    ];

    private const string SQL_INCREMENT_VOTE_SCORE = <<<'SQL'
        UPDATE forum_threads
        SET vote_score = vote_score + :delta,
            updated_at = :updated_at
        WHERE id = :id
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND deleted_at IS NULL
        SQL;

    private const string SQL_INCREMENT_REPLY_COUNT = <<<'SQL'
        UPDATE forum_threads
        SET reply_count = GREATEST(0, reply_count + :delta),
            last_activity_at = :updated_at,
            updated_at = :updated_at
        WHERE id = :id
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND deleted_at IS NULL
        SQL;

    private const string SQL_SOFT_DELETE = <<<'SQL'
        UPDATE forum_threads
        SET deleted_at = :deleted_at, updated_at = :updated_at
        WHERE id = :id
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?Thread
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, [
            'id' => $id,
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findBySlug(string $slug, ?string $tenantId = null): ?Thread
    {
        $result = $this->connection->query(self::SQL_FIND_BY_SLUG, [
            'slug' => $slug,
            'tenant_key' => $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByCategory(
        string $categoryId,
        int $page = 1,
        int $perPage = 25,
        ?ThreadStatus $status = null,
        ?ThreadType $type = null,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $bindings = [
            'category_id' => $categoryId,
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ];

        $countSql = self::SQL_COUNT_BY_CATEGORY;
        $selectSql = self::SQL_FIND_BY_CATEGORY;

        if ($status !== null) {
            $statusFilter = ' AND t.status = :status';
            $countSql .= $statusFilter;
            $selectSql .= $statusFilter;
            $bindings['status'] = $status->value;
        }

        if ($type !== null) {
            $typeFilter = ' AND t.type = :type';
            $countSql .= $typeFilter;
            $selectSql .= $typeFilter;
            $bindings['type'] = $type->value;
        }

        $countResult = $this->connection->query($countSql, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql .= ' ORDER BY t.is_pinned DESC, CASE WHEN t.last_activity_at IS NULL THEN 1 ELSE 0 END, t.last_activity_at DESC LIMIT :limit OFFSET :offset';
        $bindings['limit'] = $perPage;
        $bindings['offset'] = $offset;

        $dataResult = $this->connection->query($selectSql, $bindings);
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
        int $perPage = 25,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $tenantKey = $this->tenantId ?? self::SENTINEL_TENANT;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_AUTHOR, [
            'author_id' => $authorId,
            'tenant_key' => $tenantKey,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_AUTHOR, [
            'author_id' => $authorId,
            'tenant_key' => $tenantKey,
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

    public function findByTag(
        string $tagId,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $tenantKey = $this->tenantId ?? self::SENTINEL_TENANT;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_TAG, [
            'tag_id' => $tagId,
            'tenant_key' => $tenantKey,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_TAG, [
            'tag_id' => $tagId,
            'tenant_key' => $tenantKey,
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

    public function findRecent(
        int $page = 1,
        int $perPage = 25,
        ?string $tenantId = null,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $tenantKey = $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT;

        $countResult = $this->connection->query(self::SQL_COUNT_RECENT, [
            'tenant_key' => $tenantKey,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_RECENT, [
            'tenant_key' => $tenantKey,
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

    public function save(Thread $thread): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_threads',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
            extraWhere: 'forum_threads.version = :expected_version',
            extraSet: 'version = forum_threads.version + 1',
        );

        $affected = $this->connection->execute($sql, [
            'id' => $thread->id,
            'tenant_id' => $thread->tenantId,
            'category_id' => $thread->categoryId,
            'author_id' => $thread->authorId,
            'title' => $thread->title,
            'slug' => $thread->slug,
            'type' => $thread->type->value,
            'status' => $thread->status->value,
            'is_pinned' => $thread->isPinned,
            'is_locked' => $thread->isLocked,
            'solved_post_id' => $thread->solvedPostId,
            'reply_count' => $thread->replyCount,
            'view_count' => $thread->viewCount,
            'vote_score' => $thread->voteScore,
            'last_activity_at' => $thread->lastActivityAt?->format('c'),
            'ip_hash' => $thread->ipHash,
            'user_agent_hash' => $thread->userAgentHash,
            'created_at' => $thread->createdAt->format('c'),
            'updated_at' => $thread->updatedAt->format('c'),
            'deleted_at' => $thread->deletedAt?->format('c'),
            'version' => $thread->version,
            'expected_version' => $thread->version,
        ]);

        if ($affected === 0) {
            throw ForumException::concurrencyConflict($thread->id, $thread->version);
        }
    }

    public function delete(Thread $thread): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_SOFT_DELETE, [
            'id' => $thread->id,
            'deleted_at' => $now->format('c'),
            'updated_at' => $now->format('c'),
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
    }

    public function incrementVoteScore(string $id, int $delta): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_INCREMENT_VOTE_SCORE, [
            'id' => $id,
            'delta' => $delta,
            'updated_at' => $now->format('c'),
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
    }

    public function incrementReplyCount(string $id, int $delta = 1): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_INCREMENT_REPLY_COUNT, [
            'id' => $id,
            'delta' => $delta,
            'updated_at' => $now->format('c'),
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
    }

    private static function hydrate(Row $row): Thread
    {
        return new Thread(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            categoryId: $row->getString('category_id'),
            authorId: $row->getString('author_id'),
            title: $row->getString('title'),
            slug: $row->getString('slug'),
            type: ThreadType::from($row->getString('type')),
            status: ThreadStatus::from($row->getString('status')),
            isPinned: $row->getBool('is_pinned'),
            isLocked: $row->getBool('is_locked'),
            solvedPostId: $row->getNullableString('solved_post_id'),
            replyCount: $row->getInt('reply_count'),
            viewCount: $row->getInt('view_count'),
            voteScore: $row->getInt('vote_score'),
            lastActivityAt: self::toDateTime($row->getNullableString('last_activity_at')),
            ipHash: $row->getString('ip_hash'),
            userAgentHash: $row->getString('user_agent_hash'),
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
