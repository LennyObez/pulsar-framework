<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Repository;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\CustomEvent;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use EventRepositoryInterface for public API')]
final readonly class DbEventRepository implements EventRepositoryInterface
{
    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO analytics_events (
            id, site_id, visitor_id, session_id, event_name,
            event_props, revenue_value, pathname, created_at
        ) VALUES (
            :id, :site_id, :visitor_id, :session_id, :event_name,
            :event_props, :revenue_value, :pathname, :created_at
        )
        SQL;

    private const string SQL_FIND_BY_SITE = <<<'SQL'
        SELECT * FROM analytics_events
        WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to
        ORDER BY created_at DESC
        LIMIT :limit
        SQL;

    private const string SQL_FIND_BY_SITE_AND_NAME = <<<'SQL'
        SELECT * FROM analytics_events
        WHERE site_id = :site_id AND event_name = :event_name
            AND created_at >= :from AND created_at <= :to
        ORDER BY created_at DESC
        LIMIT :limit
        SQL;

    private const string SQL_DELETE_OLDER_THAN = <<<'SQL'
        DELETE FROM analytics_events WHERE created_at < :before
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function insert(CustomEvent $event): void
    {
        $propsJson = $event->eventProps !== [] ? json_encode($event->eventProps, JSON_THROW_ON_ERROR) : null;

        $this->connection->execute(self::SQL_INSERT, [
            'id' => $event->id,
            'site_id' => $event->siteId,
            'visitor_id' => $event->visitorId,
            'session_id' => $event->sessionId,
            'event_name' => $event->eventName,
            'event_props' => $propsJson,
            'revenue_value' => $event->revenueValue,
            'pathname' => $event->pathname,
            'created_at' => $event->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findBySite(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $eventName = null,
        int $limit = 1000,
    ): array {
        if ($eventName !== null) {
            return $this->connection->query(self::SQL_FIND_BY_SITE_AND_NAME, [
                'site_id' => $siteId,
                'event_name' => $eventName,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ])->map(self::hydrate(...));
        }

        return $this->connection->query(self::SQL_FIND_BY_SITE, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ])->map(self::hydrate(...));
    }

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return $this->connection->execute(self::SQL_DELETE_OLDER_THAN, [
            'before' => $before->format('Y-m-d H:i:s'),
        ]);
    }

    private static function hydrate(Row $row): CustomEvent
    {
        $propsRaw = $row->getNullableString('event_props');
        /** @var array<string, mixed> $props */
        $props = $propsRaw !== null ? json_decode($propsRaw, true, flags: JSON_THROW_ON_ERROR) : [];

        $revenueRaw = $row->get('revenue_value');
        $revenue = $revenueRaw !== null ? (float) $revenueRaw : null;

        return new CustomEvent(
            id: $row->getString('id'),
            siteId: $row->getString('site_id'),
            visitorId: $row->getString('visitor_id'),
            sessionId: $row->getString('session_id'),
            eventName: $row->getString('event_name'),
            eventProps: $props,
            revenueValue: $revenue,
            pathname: $row->getString('pathname'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
