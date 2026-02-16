<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\InListBuilder;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;
use Pulsar\Extension\Cms\Seo\LinkHealthRepositoryInterface;

use function count;
use function max;

#[Internal(reason: 'Raw-DB repository; use LinkHealthRepositoryInterface for public API')]
final readonly class DbLinkHealthRepository implements LinkHealthRepositoryInterface
{
    private const string SQL_FIND_BY_CONTENT = <<<'SQL'
        SELECT * FROM cms_link_health_checks
        WHERE source_content_id = :content_id AND source_locale = :locale
        ORDER BY created_at DESC
        SQL;

    private const string SQL_FIND_BROKEN = <<<'SQL'
        SELECT * FROM cms_link_health_checks
        WHERE is_broken = true
        SQL;

    // SQL_FIND_BY_CONTENT_IDS built dynamically via InListBuilder for portability

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'source_content_id', 'source_locale', 'target_url',
        'is_broken', 'is_redirected', 'http_status_code', 'last_checked_at', 'created_at',
    ];

    private const array UPSERT_UPDATE = ['is_broken', 'is_redirected', 'http_status_code', 'last_checked_at'];

    private const string SQL_DELETE_BY_CONTENT = <<<'SQL'
        DELETE FROM cms_link_health_checks
        WHERE source_content_id = :content_id AND source_locale = :locale
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findByContent(string $contentId, string $locale): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CONTENT, [
            'content_id' => $contentId,
            'locale' => $locale,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findByContentIds(array $contentIds, string $locale): array
    {
        if ($contentIds === []) {
            return [];
        }

        $inClause = InListBuilder::compile($this->connection->driver(), 'source_content_id', 'content_ids', count($contentIds));
        $sql = "SELECT * FROM cms_link_health_checks WHERE $inClause AND source_locale = :locale ORDER BY source_content_id, created_at DESC";
        $bindings = InListBuilder::expandParams($this->connection->driver(), 'content_ids', $contentIds);
        $bindings['locale'] = $locale;

        $result = $this->connection->query($sql, $bindings);

        $grouped = [];

        foreach ($result->rows as $row) {
            $check = self::hydrate($row);
            $grouped[$check->sourceContentId][] = $check;
        }

        return $grouped;
    }

    public function findBroken(?string $tenantId = null, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $sql = self::SQL_FIND_BROKEN;
        $bindings = [];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $sql .= ' ORDER BY last_checked_at DESC LIMIT :limit OFFSET :offset';
        $bindings['limit'] = $perPage;
        $bindings['offset'] = $offset;

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrate(...));
    }

    public function save(LinkHealthCheck $check): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_link_health_checks',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $check->id,
            'tenant_id' => $check->tenantId,
            'source_content_id' => $check->sourceContentId,
            'source_locale' => $check->sourceLocale,
            'target_url' => $check->targetUrl,
            'is_broken' => $check->isBroken,
            'is_redirected' => $check->isRedirected,
            'http_status_code' => $check->httpStatusCode,
            'last_checked_at' => $check->lastCheckedAt->format('c'),
            'created_at' => $check->createdAt->format('c'),
        ]);
    }

    public function deleteByContent(string $contentId, string $locale): void
    {
        $this->connection->execute(self::SQL_DELETE_BY_CONTENT, [
            'content_id' => $contentId,
            'locale' => $locale,
        ]);
    }

    private static function hydrate(Row $row): LinkHealthCheck
    {
        return new LinkHealthCheck(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            sourceContentId: $row->getString('source_content_id'),
            sourceLocale: $row->getString('source_locale'),
            targetUrl: $row->getString('target_url'),
            isBroken: $row->getBool('is_broken'),
            isRedirected: $row->getBool('is_redirected'),
            httpStatusCode: $row->getNullableInt('http_status_code'),
            lastCheckedAt: new DateTimeImmutable($row->getString('last_checked_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
