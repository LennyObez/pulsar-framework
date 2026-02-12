<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Internal\Persistence;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\WebhookEvent;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;

use function ceil;
use function max;

/**
 * Database-backed webhook event repository.
 */
#[Internal(reason: 'Raw-DB repository — use WebhookEventRepositoryInterface for public API')]
final readonly class DbWebhookEventRepository implements WebhookEventRepositoryInterface
{
    private const string SQL_COUNT_BY_TYPE = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM webhook_events e
        WHERE e.event_type = :event_type
        SQL;

    private const string SQL_FIND_BY_TYPE = <<<'SQL'
        SELECT e.*
        FROM webhook_events e
        WHERE e.event_type = :event_type
        ORDER BY e.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'store', 'event_type', 'payload_encrypted',
        'signature_verified', 'processed_at', 'created_at',
    ];

    private const array UPSERT_UPDATE = [
        'payload_encrypted', 'signature_verified', 'processed_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function save(WebhookEvent $event): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'webhook_events',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $event->id,
            'store' => $event->store->value,
            'event_type' => $event->eventType,
            'payload_encrypted' => $event->payloadEncrypted,
            'signature_verified' => $event->signatureVerified ? 1 : 0,
            'processed_at' => $event->processedAt?->format('c'),
            'created_at' => $event->createdAt->format('c'),
        ]);
    }

    #[Override]
    public function findByEventType(string $eventType, int $page = 1, int $perPage = 20): PaginationResult
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_TYPE, [
            'event_type' => $eventType,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_TYPE, [
            'event_type' => $eventType,
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

    private static function hydrate(Row $row): WebhookEvent
    {
        return new WebhookEvent(
            id: $row->getString('id'),
            store: Store::from($row->getString('store')),
            eventType: $row->getString('event_type'),
            payloadEncrypted: $row->getString('payload_encrypted'),
            signatureVerified: $row->getInt('signature_verified') === 1,
            processedAt: self::toDateTime($row->getNullableString('processed_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
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
