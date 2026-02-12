<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Newsletter\CampaignStatus;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaign;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaignRepositoryInterface;

use function ceil;
use function max;

/**
 * Database-backed newsletter campaign repository.
 */
#[Internal(reason: 'Raw-DB repository — use NewsletterCampaignRepositoryInterface for public API')]
final readonly class DbNewsletterCampaignRepository implements NewsletterCampaignRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT c.*
        FROM cms_newsletter_campaigns c
        WHERE c.id = :id
        SQL;

    private const string SQL_FIND_BY_STATUS = <<<'SQL'
        SELECT c.*
        FROM cms_newsletter_campaigns c
        WHERE c.status = :status
        SQL;

    private const string SQL_COUNT_ALL = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_newsletter_campaigns c
        SQL;

    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT c.*
        FROM cms_newsletter_campaigns c
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM cms_newsletter_campaigns WHERE id = :id
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'subject', 'body_html', 'body_text',
        'locale', 'status', 'scheduled_at', 'sent_at',
        'recipient_count', 'opened_count', 'clicked_count', 'bounced_count',
        'created_by', 'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = [
        'subject', 'body_html', 'body_text', 'locale', 'status',
        'scheduled_at', 'sent_at', 'recipient_count',
        'opened_count', 'clicked_count', 'bounced_count', 'updated_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(NewsletterCampaign $campaign): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_newsletter_campaigns',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $campaign->id,
            'tenant_id' => $campaign->tenantId,
            'subject' => $campaign->subject,
            'body_html' => $campaign->bodyHtml,
            'body_text' => $campaign->bodyText,
            'locale' => $campaign->locale,
            'status' => $campaign->status->value,
            'scheduled_at' => $campaign->scheduledAt?->format('c'),
            'sent_at' => $campaign->sentAt?->format('c'),
            'recipient_count' => $campaign->recipientCount,
            'opened_count' => $campaign->openedCount,
            'clicked_count' => $campaign->clickedCount,
            'bounced_count' => $campaign->bouncedCount,
            'created_by' => $campaign->createdBy,
            'created_at' => $campaign->createdAt->format('c'),
            'updated_at' => $campaign->updatedAt->format('c'),
        ]);
    }

    public function findById(string $id): ?NewsletterCampaign
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByStatus(CampaignStatus $status, ?string $tenantId = null): array
    {
        $sql = self::SQL_FIND_BY_STATUS;
        $bindings = ['status' => $status->value];

        if ($tenantId !== null) {
            $sql .= ' AND c.tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $sql .= ' ORDER BY c.created_at DESC';

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrate(...));
    }

    public function findAllByTenant(
        ?string $tenantId = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $bindings = [];

        $countSql = self::SQL_COUNT_ALL;
        $selectSql = self::SQL_FIND_ALL;

        if ($tenantId !== null) {
            $tenantFilter = ' WHERE c.tenant_id = :tenant_id';
            $countSql .= $tenantFilter;
            $selectSql .= $tenantFilter;
            $bindings['tenant_id'] = $tenantId;
        }

        $countResult = $this->connection->query($countSql, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql .= ' ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset';
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

    public function delete(NewsletterCampaign $campaign): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $campaign->id]);
    }

    private static function hydrate(Row $row): NewsletterCampaign
    {
        return new NewsletterCampaign(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            subject: $row->getString('subject'),
            bodyHtml: $row->getString('body_html'),
            bodyText: $row->getNullableString('body_text'),
            locale: $row->getString('locale'),
            status: CampaignStatus::from($row->getString('status')),
            scheduledAt: self::toDateTime($row->getNullableString('scheduled_at')),
            sentAt: self::toDateTime($row->getNullableString('sent_at')),
            recipientCount: $row->getInt('recipient_count'),
            openedCount: $row->getInt('opened_count'),
            clickedCount: $row->getInt('clicked_count'),
            bouncedCount: $row->getInt('bounced_count'),
            createdBy: $row->getNullableString('created_by'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
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
