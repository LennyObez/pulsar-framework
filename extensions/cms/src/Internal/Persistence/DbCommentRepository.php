<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\ModerationStatus;
use Pulsar\Extension\Cms\Content\DataClassification;

use function ceil;
use function max;

/**
 * @psalm-api Bound to CommentRepositoryInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Raw-DB repository; use CommentRepositoryInterface for public API')]
final readonly class DbCommentRepository implements CommentRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT c.*
        FROM cms_comments c
        WHERE c.id = :id AND c.deleted_at IS NULL
        SQL;

    private const string SQL_COUNT_BY_CONTENT = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_comments c
        WHERE c.content_id = :content_id
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_CONTENT = <<<'SQL'
        SELECT c.*
        FROM cms_comments c
        WHERE c.content_id = :content_id
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_COUNT_PENDING = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_comments c
        WHERE c.status = 'pending'
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_PENDING = <<<'SQL'
        SELECT c.*
        FROM cms_comments c
        WHERE c.status = 'pending'
            AND c.deleted_at IS NULL
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'content_id', 'parent_id', 'author_id',
        'guest_name', 'guest_email', 'body', 'status',
        'ip_hash', 'user_agent_hash', 'edited_at',
        'edit_window_expires_at', 'data_classification',
        'created_at', 'deleted_at',
    ];

    private const array UPSERT_UPDATE = ['body', 'status', 'edited_at', 'deleted_at'];

    private const string SQL_SOFT_DELETE = <<<'SQL'
        UPDATE cms_comments
        SET deleted_at = :deleted_at
        WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?Comment
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByContent(
        string $contentId,
        ?ModerationStatus $status = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $bindings = ['content_id' => $contentId];

        $countSql = self::SQL_COUNT_BY_CONTENT;
        $selectSql = self::SQL_FIND_BY_CONTENT;

        if ($status !== null) {
            $statusFilter = ' AND c.status = :status';
            $countSql .= $statusFilter;
            $selectSql .= $statusFilter;
            $bindings['status'] = $status->value;
        }

        $countResult = $this->connection->query($countSql, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql .= ' ORDER BY c.created_at ASC LIMIT :limit OFFSET :offset';
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

    public function findPendingModeration(
        ?string $tenantId = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $bindings = [];

        $countSql = self::SQL_COUNT_PENDING;
        $selectSql = self::SQL_FIND_PENDING;

        if ($tenantId !== null) {
            $tenantFilter = ' AND c.tenant_id = :tenant_id';
            $countSql .= $tenantFilter;
            $selectSql .= $tenantFilter;
            $bindings['tenant_id'] = $tenantId;
        }

        $countResult = $this->connection->query($countSql, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql .= ' ORDER BY c.created_at ASC LIMIT :limit OFFSET :offset';
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

    public function save(Comment $comment): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_comments',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $comment->id,
            'tenant_id' => $comment->tenantId,
            'content_id' => $comment->contentId,
            'parent_id' => $comment->parentId,
            'author_id' => $comment->authorId,
            'guest_name' => $comment->guestName,
            'guest_email' => $comment->guestEmail,
            'body' => $comment->body,
            'status' => $comment->status->value,
            'ip_hash' => $comment->ipHash,
            'user_agent_hash' => $comment->userAgentHash,
            'edited_at' => $comment->editedAt?->format('c'),
            'edit_window_expires_at' => $comment->editWindowExpiresAt?->format('c'),
            'data_classification' => $comment->dataClassification->value,
            'created_at' => $comment->createdAt->format('c'),
            'deleted_at' => $comment->deletedAt?->format('c'),
        ]);
    }

    public function delete(Comment $comment): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_SOFT_DELETE, [
            'id' => $comment->id,
            'deleted_at' => $now->format('c'),
        ]);
    }

    private static function hydrate(Row $row): Comment
    {
        return new Comment(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            contentId: $row->getString('content_id'),
            parentId: $row->getNullableString('parent_id'),
            authorId: $row->getNullableString('author_id'),
            guestName: $row->getNullableString('guest_name'),
            guestEmail: $row->getNullableString('guest_email'),
            body: $row->getString('body'),
            status: ModerationStatus::from($row->getString('status')),
            ipHash: $row->getString('ip_hash'),
            userAgentHash: $row->getString('user_agent_hash'),
            editedAt: self::toDateTime($row->getNullableString('edited_at')),
            editWindowExpiresAt: self::toDateTime($row->getNullableString('edit_window_expires_at')),
            dataClassification: DataClassification::from($row->getString('data_classification')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            deletedAt: self::toDateTime($row->getNullableString('deleted_at')),
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
