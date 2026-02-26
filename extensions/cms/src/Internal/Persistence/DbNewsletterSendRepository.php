<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Newsletter\NewsletterSend;
use Pulsar\Extension\Cms\Newsletter\NewsletterSendRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\SendStatus;

use function ceil;
use function max;

/**
 * Database-backed newsletter send repository.
 *
 * Supports batch inserts for efficient campaign dispatch where thousands
 * of send records are created simultaneously.
 */
#[Internal(reason: 'Raw-DB repository — use NewsletterSendRepositoryInterface for public API')]
final readonly class DbNewsletterSendRepository implements NewsletterSendRepositoryInterface
{
    private const string SQL_COUNT_BY_CAMPAIGN = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_newsletter_sends s
        WHERE s.campaign_id = :campaign_id
        SQL;

    private const string SQL_FIND_BY_CAMPAIGN = <<<'SQL'
        SELECT s.*
        FROM cms_newsletter_sends s
        WHERE s.campaign_id = :campaign_id
        SQL;

    private const string SQL_FIND_BY_SUBSCRIBER = <<<'SQL'
        SELECT s.*
        FROM cms_newsletter_sends s
        WHERE s.subscriber_id = :subscriber_id
        ORDER BY s.sent_at DESC
        SQL;

    private const string SQL_UPDATE_STATUS = <<<'SQL'
        UPDATE cms_newsletter_sends
        SET status = :status, bounce_reason = :bounce_reason
        WHERE id = :id
        SQL;

    private const string SQL_COUNT_BY_CAMPAIGN_STATUS = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM cms_newsletter_sends s
        WHERE s.campaign_id = :campaign_id AND s.status = :status
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'campaign_id', 'subscriber_id', 'status',
        'sent_at', 'opened_at', 'clicked_at', 'bounce_reason',
    ];

    private const array UPSERT_UPDATE = [
        'status', 'sent_at', 'opened_at', 'clicked_at', 'bounce_reason',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(NewsletterSend $send): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_newsletter_sends',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, self::toBindings($send));
    }

    public function saveBatch(array $sends): void
    {
        if ($sends === []) {
            return;
        }

        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_newsletter_sends',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        foreach ($sends as $send) {
            $this->connection->execute($sql, self::toBindings($send));
        }
    }

    public function findByCampaignId(
        string $campaignId,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $bindings = ['campaign_id' => $campaignId];

        $countResult = $this->connection->query(self::SQL_COUNT_BY_CAMPAIGN, $bindings);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql = self::SQL_FIND_BY_CAMPAIGN . ' ORDER BY s.sent_at ASC LIMIT :limit OFFSET :offset';
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

    public function findBySubscriberId(string $subscriberId): array
    {
        $result = $this->connection->query(
            self::SQL_FIND_BY_SUBSCRIBER,
            ['subscriber_id' => $subscriberId],
        );

        return $result->map(self::hydrate(...));
    }

    public function updateStatus(string $sendId, SendStatus $status, ?string $bounceReason = null): void
    {
        $this->connection->execute(self::SQL_UPDATE_STATUS, [
            'id' => $sendId,
            'status' => $status->value,
            'bounce_reason' => $bounceReason,
        ]);
    }

    public function countByCampaignAndStatus(string $campaignId, SendStatus $status): int
    {
        $result = $this->connection->query(self::SQL_COUNT_BY_CAMPAIGN_STATUS, [
            'campaign_id' => $campaignId,
            'status' => $status->value,
        ]);

        return $result->first()?->getInt('total') ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toBindings(NewsletterSend $send): array
    {
        return [
            'id' => $send->id,
            'campaign_id' => $send->campaignId,
            'subscriber_id' => $send->subscriberId,
            'status' => $send->status->value,
            'sent_at' => $send->sentAt?->format('c'),
            'opened_at' => $send->openedAt?->format('c'),
            'clicked_at' => $send->clickedAt?->format('c'),
            'bounce_reason' => $send->bounceReason,
        ];
    }

    private static function hydrate(Row $row): NewsletterSend
    {
        return new NewsletterSend(
            id: $row->getString('id'),
            campaignId: $row->getString('campaign_id'),
            subscriberId: $row->getString('subscriber_id'),
            status: SendStatus::from($row->getString('status')),
            sentAt: self::toDateTime($row->getNullableString('sent_at')),
            openedAt: self::toDateTime($row->getNullableString('opened_at')),
            clickedAt: self::toDateTime($row->getNullableString('clicked_at')),
            bounceReason: $row->getNullableString('bounce_reason'),
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
