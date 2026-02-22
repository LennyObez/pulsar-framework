<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
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
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.id = :id AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_SLUG = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.slug = :slug
            AND t.tenant_id IS NOT DISTINCT FROM :tenant_id
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_COUNT_BY_CATEGORY = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_threads t
        WHERE t.category_id = :category_id
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_CATEGORY = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.category_id = :category_id
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_COUNT_BY_AUTHOR = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_threads t
        WHERE t.author_id = :author_id
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_AUTHOR = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.author_id = :author_id
            AND t.deleted_at IS NULL
        ORDER BY t.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_COUNT_BY_TAG = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_threads t
        INNER JOIN forum_thread_tags tt ON tt.thread_id = t.id
        WHERE tt.tag_id = :tag_id
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_TAG = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        INNER JOIN forum_thread_tags tt ON tt.thread_id = t.id
        WHERE tt.tag_id = :tag_id
            AND t.deleted_at IS NULL
        ORDER BY t.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_COUNT_RECENT = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_threads t
        WHERE t.tenant_id IS NOT DISTINCT FROM :tenant_id
            AND t.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_RECENT = <<<'SQL'
        SELECT t.*
        FROM forum_threads t
        WHERE t.tenant_id IS NOT DISTINCT FROM :tenant_id
            AND t.deleted_at IS NULL
        ORDER BY t.is_pinned DESC, t.last_activity_at DESC NULLS LAST
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO forum_threads (
            id, tenant_id, category_id, author_id, title, slug,
            type, status, is_pinned, is_locked, solved_post_id,
            reply_count, view_count, vote_score, last_activity_at,
            ip_hash, user_agent_hash,
            created_at, updated_at, deleted_at, version
        ) VALUES (
            :id, :tenant_id, :category_id, :author_id, :title, :slug,
            :type, :status, :is_pinned, :is_locked, :solved_post_id,
            :reply_count, :view_count, :vote_score, :last_activity_at,
            :ip_hash, :user_agent_hash,
            :created_at, :updated_at, :deleted_at, :version
        )
        ON CONFLICT (id) DO UPDATE SET
            category_id = EXCLUDED.category_id,
            title = EXCLUDED.title,
            slug = EXCLUDED.slug,
            type = EXCLUDED.type,
            status = EXCLUDED.status,
            is_pinned = EXCLUDED.is_pinned,
            is_locked = EXCLUDED.is_locked,
            solved_post_id = EXCLUDED.solved_post_id,
            reply_count = EXCLUDED.reply_count,
            view_count = EXCLUDED.view_count,
            vote_score = EXCLUDED.vote_score,
            last_activity_at = EXCLUDED.last_activity_at,
            updated_at = EXCLUDED.updated_at,
            deleted_at = EXCLUDED.deleted_at,
            version = forum_threads.version + 1
        WHERE forum_threads.version = :expected_version
        SQL;

    private const string SQL_INCREMENT_VOTE_SCORE = <<<'SQL'
        UPDATE forum_threads
        SET vote_score = vote_score + :delta,
            updated_at = :updated_at
        WHERE id = :id AND deleted_at IS NULL
        SQL;

    private const string SQL_INCREMENT_REPLY_COUNT = <<<'SQL'
        UPDATE forum_threads
        SET reply_count = GREATEST(0, reply_count + :delta),
            last_activity_at = :updated_at,
            updated_at = :updated_at
        WHERE id = :id AND deleted_at IS NULL
        SQL;

    private const string SQL_SOFT_DELETE = <<<'SQL'
        UPDATE forum_threads
        SET deleted_at = :deleted_at, updated_at = :updated_at
        WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?Thread
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
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
            'tenant_id' => $tenantId ?? $this->tenantId,
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

        $bindings = ['category_id' => $categoryId];

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

        $selectSql .= ' ORDER BY t.is_pinned DESC, t.last_activity_at DESC NULLS LAST LIMIT :limit OFFSET :offset';
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

        $countResult = $this->connection->query(self::SQL_COUNT_BY_AUTHOR, [
            'author_id' => $authorId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_AUTHOR, [
            'author_id' => $authorId,
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

        $countResult = $this->connection->query(self::SQL_COUNT_BY_TAG, [
            'tag_id' => $tagId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_TAG, [
            'tag_id' => $tagId,
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
        $effectiveTenantId = $tenantId ?? $this->tenantId;

        $countResult = $this->connection->query(self::SQL_COUNT_RECENT, [
            'tenant_id' => $effectiveTenantId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_RECENT, [
            'tenant_id' => $effectiveTenantId,
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
        $affected = $this->connection->execute(self::SQL_UPSERT, [
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

    public function incrementReplyCount(string $id, int $delta = 1): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_INCREMENT_REPLY_COUNT, [
            'id' => $id,
            'delta' => $delta,
            'updated_at' => $now->format('c'),
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
