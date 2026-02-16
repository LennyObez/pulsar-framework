<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use InvalidArgumentException;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\InListBuilder;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;

use function array_map;
use function ceil;
use function count;
use function implode;
use function max;
use function preg_match;
use function range;
use function sprintf;

#[Internal(reason: 'Raw-DB repository; use ContentRepositoryInterface for public API')]
final readonly class DbContentRepository implements ContentRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT c.*
        FROM cms_contents c
        WHERE c.id = :id
            AND COALESCE(c.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_PATH = <<<'SQL'
        SELECT c.*
        FROM cms_contents c
        INNER JOIN cms_content_translations ct ON ct.content_id = c.id
        WHERE ct.locale = :locale
            AND ct.path = :path
            AND COALESCE(ct.tenant_id, '00000000-0000-0000-0000-000000000000') = COALESCE(:tenant_id, '00000000-0000-0000-0000-000000000000')
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_COUNT_PUBLISHED = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_contents c
        INNER JOIN cms_content_translations ct ON ct.content_id = c.id
        WHERE c.status = 'published'
            AND ct.locale = :locale
            AND COALESCE(ct.tenant_id, '00000000-0000-0000-0000-000000000000') = COALESCE(:tenant_id, '00000000-0000-0000-0000-000000000000')
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_PUBLISHED = <<<'SQL'
        SELECT c.*
        FROM cms_contents c
        INNER JOIN cms_content_translations ct ON ct.content_id = c.id
        WHERE c.status = 'published'
            AND ct.locale = :locale
            AND COALESCE(ct.tenant_id, '00000000-0000-0000-0000-000000000000') = COALESCE(:tenant_id, '00000000-0000-0000-0000-000000000000')
            AND c.deleted_at IS NULL
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'content_type', 'author_id', 'status',
        'scheduled_publish_at', 'scheduled_unpublish_at', 'published_at',
        'created_at', 'updated_at', 'deleted_at', 'template',
        'parent_id', 'sort_order', 'comment_policy', 'data_classification', 'version',
    ];

    private const array UPSERT_UPDATE = [
        'status', 'scheduled_publish_at', 'scheduled_unpublish_at', 'published_at',
        'updated_at', 'deleted_at', 'template', 'parent_id', 'sort_order',
        'comment_policy', 'data_classification',
    ];

    private const string SQL_SOFT_DELETE = <<<'SQL'
        UPDATE cms_contents
        SET deleted_at = :deleted_at, updated_at = :updated_at
        WHERE id = :id
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    // SQL_FIND_BY_IDS built dynamically via InListBuilder for portability

    private const string SQL_FIND_ANCESTORS = <<<'SQL'
        WITH RECURSIVE ancestors AS (
            SELECT c.*, 1 AS depth
            FROM cms_contents c
            WHERE c.id = (
                SELECT parent_id FROM cms_contents WHERE id = :content_id AND deleted_at IS NULL
            ) AND c.deleted_at IS NULL
              AND COALESCE(c.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            UNION ALL
            SELECT c.*, a.depth + 1
            FROM cms_contents c
            INNER JOIN ancestors a ON c.id = a.parent_id
            WHERE c.deleted_at IS NULL AND a.depth < :max_depth
        )
        SELECT * FROM ancestors ORDER BY depth ASC
        SQL;

    private const string SQL_FIND_DESCENDANTS = <<<'SQL'
        WITH RECURSIVE descendants AS (
            SELECT c.* FROM cms_contents c
            WHERE c.parent_id = :parent_id AND c.deleted_at IS NULL
                AND COALESCE(c.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            UNION ALL
            SELECT c.* FROM cms_contents c
            INNER JOIN descendants d ON c.parent_id = d.id
            WHERE c.deleted_at IS NULL
        )
        SELECT * FROM descendants
        SQL;

    private const string SQL_FIND_SCHEDULED_FOR_PUBLISHING = <<<'SQL'
        SELECT c.*
        FROM cms_contents c
        WHERE c.status = 'scheduled'
            AND c.scheduled_publish_at IS NOT NULL
            AND c.scheduled_publish_at <= :now
            AND COALESCE(c.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND c.deleted_at IS NULL
        SQL;

    private const string SQL_FIND_SCHEDULED_FOR_UNPUBLISHING = <<<'SQL'
        SELECT c.*
        FROM cms_contents c
        WHERE c.status = 'published'
            AND c.scheduled_unpublish_at IS NOT NULL
            AND c.scheduled_unpublish_at <= :now
            AND COALESCE(c.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
            AND c.deleted_at IS NULL
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?Content
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, [
            'id' => $id,
            'tenant_key' => $this->tenantId ?? '00000000-0000-0000-0000-000000000000',
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByImportId(string $importId): ?Content
    {
        $result = $this->connection->query(
            'SELECT * FROM cms_contents WHERE import_id = :import_id LIMIT 1',
            ['import_id' => $importId],
        );
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?Content
    {
        $result = $this->connection->query(self::SQL_FIND_BY_PATH, [
            'locale' => $locale,
            'path' => $path,
            'tenant_id' => $tenantId ?? $this->tenantId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findPublished(
        string $locale,
        ?string $contentType = null,
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult {
        $effectiveTenantId = $tenantId ?? $this->tenantId;
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $bindings = ['locale' => $locale, 'tenant_id' => $effectiveTenantId];

        $countSql = self::SQL_COUNT_PUBLISHED;
        $selectSql = self::SQL_FIND_PUBLISHED;

        if ($contentType !== null) {
            $typeFilter = ' AND c.content_type = :content_type';
            $countSql .= $typeFilter;
            $selectSql .= $typeFilter;
            $bindings['content_type'] = $contentType;
        }

        $countResult = $this->connection->query($countSql, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql .= ' ORDER BY c.published_at DESC LIMIT :limit OFFSET :offset';
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

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid UUID in findByIds: %s', $id));
            }
        }

        $inClause = InListBuilder::compile($this->connection->driver(), 'c.id', 'ids', count($ids));
        $sql = "SELECT c.* FROM cms_contents c WHERE $inClause AND COALESCE(c.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key AND c.deleted_at IS NULL";
        $bindings = InListBuilder::expandParams($this->connection->driver(), 'ids', $ids);
        $bindings['tenant_key'] = $this->tenantId ?? '00000000-0000-0000-0000-000000000000';

        $result = $this->connection->query($sql, $bindings);

        $indexed = [];

        foreach ($result->rows as $row) {
            $content = self::hydrate($row);
            $indexed[$content->id] = $content;
        }

        return $indexed;
    }

    public function findAncestors(string $contentId, int $maxDepth = 20): array
    {
        $result = $this->connection->query(self::SQL_FIND_ANCESTORS, [
            'content_id' => $contentId,
            'max_depth' => $maxDepth,
            'tenant_key' => $this->tenantId ?? '00000000-0000-0000-0000-000000000000',
        ]);

        return $result->map(self::hydrate(...));
    }

    public function save(Content $content): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_contents',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
            extraWhere: 'cms_contents.version = :expected_version',
            extraSet: 'version = cms_contents.version + 1',
        );

        $affected = $this->connection->execute($sql, [
            'id' => $content->id,
            'tenant_id' => $content->tenantId,
            'content_type' => $content->contentType->value,
            'author_id' => $content->authorId,
            'status' => $content->status->value,
            'scheduled_publish_at' => $content->scheduledPublishAt?->format('c'),
            'scheduled_unpublish_at' => $content->scheduledUnpublishAt?->format('c'),
            'published_at' => $content->publishedAt?->format('c'),
            'created_at' => $content->createdAt->format('c'),
            'updated_at' => $content->updatedAt->format('c'),
            'deleted_at' => $content->deletedAt?->format('c'),
            'template' => $content->template,
            'parent_id' => $content->parentId,
            'sort_order' => $content->sortOrder,
            'comment_policy' => $content->commentPolicy->value,
            'data_classification' => $content->dataClassification->value,
            'version' => $content->version,
            'expected_version' => $content->version,
        ]);

        if ($affected === 0) {
            throw CmsException::concurrencyConflict($content->id, $content->version);
        }
    }

    public function delete(Content $content): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_SOFT_DELETE, [
            'id' => $content->id,
            'deleted_at' => $now->format('c'),
            'updated_at' => $now->format('c'),
            'tenant_key' => $this->tenantId ?? '00000000-0000-0000-0000-000000000000',
        ]);
    }

    public function findDescendants(string $contentId): array
    {
        $result = $this->connection->query(self::SQL_FIND_DESCENDANTS, [
            'parent_id' => $contentId,
            'tenant_key' => $this->tenantId ?? '00000000-0000-0000-0000-000000000000',
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findScheduledForPublishing(DateTimeImmutable $now): array
    {
        $result = $this->connection->query(self::SQL_FIND_SCHEDULED_FOR_PUBLISHING, [
            'now' => $now->format('c'),
            'tenant_key' => $this->tenantId ?? '00000000-0000-0000-0000-000000000000',
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findScheduledForUnpublishing(DateTimeImmutable $now): array
    {
        $result = $this->connection->query(self::SQL_FIND_SCHEDULED_FOR_UNPUBLISHING, [
            'now' => $now->format('c'),
            'tenant_key' => $this->tenantId ?? '00000000-0000-0000-0000-000000000000',
        ]);

        return $result->map(self::hydrate(...));
    }

    public function bulkUpdateStatus(array $ids, PublishingStatus $status, ?string $tenantId = null): int
    {
        if ($ids === []) {
            return 0;
        }

        self::validateIds($ids);

        $placeholders = implode(', ', array_map(
            static fn(int $i): string => ':id_' . $i,
            range(0, count($ids) - 1),
        ));

        $sql = "UPDATE cms_contents SET status = :status, updated_at = :updated_at WHERE id IN ($placeholders) AND deleted_at IS NULL";

        $bindings = ['status' => $status->value, 'updated_at' => new DateTimeImmutable()->format('c')];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        foreach ($ids as $i => $id) {
            $bindings['id_' . $i] = $id;
        }

        return $this->connection->execute($sql, $bindings);
    }

    public function bulkDelete(array $ids, ?string $tenantId = null): int
    {
        if ($ids === []) {
            return 0;
        }

        self::validateIds($ids);

        $placeholders = implode(', ', array_map(
            static fn(int $i): string => ':id_' . $i,
            range(0, count($ids) - 1),
        ));

        $now = new DateTimeImmutable()->format('c');
        $sql = "UPDATE cms_contents SET deleted_at = :deleted_at, updated_at = :updated_at WHERE id IN ($placeholders) AND deleted_at IS NULL";

        $bindings = ['deleted_at' => $now, 'updated_at' => $now];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        foreach ($ids as $i => $id) {
            $bindings['id_' . $i] = $id;
        }

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * @param list<string> $ids
     */
    private static function validateIds(array $ids): void
    {
        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid UUID: %s', $id));
            }
        }
    }

    private static function hydrate(Row $row): Content
    {
        return new Content(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            contentType: ContentType::from($row->getString('content_type')),
            authorId: $row->getString('author_id'),
            status: PublishingStatus::from($row->getString('status')),
            scheduledPublishAt: self::toDateTime($row->getNullableString('scheduled_publish_at')),
            scheduledUnpublishAt: self::toDateTime($row->getNullableString('scheduled_unpublish_at')),
            publishedAt: self::toDateTime($row->getNullableString('published_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
            deletedAt: self::toDateTime($row->getNullableString('deleted_at')),
            template: $row->getNullableString('template'),
            parentId: $row->getNullableString('parent_id'),
            sortOrder: $row->getInt('sort_order'),
            commentPolicy: CommentPolicy::from($row->getString('comment_policy')),
            dataClassification: DataClassification::from($row->getString('data_classification')),
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
