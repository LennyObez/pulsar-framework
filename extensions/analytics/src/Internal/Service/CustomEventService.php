<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Analytics\Contracts\CustomEventServiceInterface;

use function array_slice;
use function is_scalar;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Custom event querying and property exploration.
 */
#[Internal(reason: 'Custom event service; use CustomEventServiceInterface')]
final readonly class CustomEventService implements CustomEventServiceInterface
{
    private const string SQL_EVENT_NAMES = <<<'SQL'
        SELECT
            event_name,
            COUNT(*) AS count,
            COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_events
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
        GROUP BY event_name
        ORDER BY count DESC
        LIMIT :limit
        SQL;

    private const string SQL_EVENT_TIMESERIES = <<<'SQL'
        SELECT
            DATE(created_at) AS date,
            COUNT(*) AS count
        FROM analytics_events
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
            AND event_name = :event_name
        GROUP BY DATE(created_at)
        ORDER BY date
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function getEventNames(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 50,
    ): array {
        $result = $this->connection->query(self::SQL_EVENT_NAMES, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ]);

        $events = [];

        foreach ($result->rows as $row) {
            $events[] = [
                'event_name' => $row->getString('event_name'),
                'count' => $row->getInt('count'),
                'visitors' => $row->getInt('visitors'),
            ];
        }

        return $events;
    }

    #[Override]
    public function getEventProperties(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $eventName,
        int $limit = 20,
    ): array {
        $result = $this->connection->query(
            'SELECT event_props FROM analytics_events WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to AND event_name = :event_name LIMIT 1000',
            [
                'site_id' => $siteId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'event_name' => $eventName,
            ],
        );

        /** @var array<string, array<string, int>> $propCounts */
        $propCounts = [];

        foreach ($result->rows as $row) {
            $propsJson = $row->getNullableString('event_props');

            if ($propsJson === null) {
                continue;
            }

            /** @var array<string, mixed> $props */
            $props = json_decode($propsJson, true, flags: JSON_THROW_ON_ERROR);

            /** @var mixed $value */
            foreach ($props as $key => $value) {
                $valueStr = is_scalar($value) ? (string) $value : '';

                if (!isset($propCounts[$key])) {
                    $propCounts[$key] = [];
                }

                $propCounts[$key][$valueStr] = ($propCounts[$key][$valueStr] ?? 0) + 1;
            }
        }

        $properties = [];

        foreach ($propCounts as $property => $values) {
            arsort($values);

            foreach (array_slice($values, 0, 5) as $value => $count) {
                $properties[] = [
                    'property' => $property,
                    'value' => $value,
                    'count' => $count,
                ];
            }
        }

        usort($properties, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($properties, 0, $limit);
    }

    #[Override]
    public function getEventTimeseries(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $eventName,
    ): array {
        $result = $this->connection->query(self::SQL_EVENT_TIMESERIES, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'event_name' => $eventName,
        ]);

        $data = [];

        foreach ($result->rows as $row) {
            $data[] = [
                'date' => $row->getString('date'),
                'count' => $row->getInt('count'),
            ];
        }

        return $data;
    }
}
