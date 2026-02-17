<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Custom event querying and exploration.
 */
#[Api(since: '1.0.0')]
interface CustomEventServiceInterface
{
    /**
     * Get distinct event names with counts.
     *
     * @return list<array{event_name: string, count: int, visitors: int}>
     */
    public function getEventNames(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 50,
    ): array;

    /**
     * Get event properties breakdown for a specific event name.
     *
     * @return list<array{property: string, value: string, count: int}>
     */
    public function getEventProperties(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $eventName,
        int $limit = 20,
    ): array;

    /**
     * Get event time-series data.
     *
     * @return list<array{date: string, count: int}>
     */
    public function getEventTimeseries(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $eventName,
    ): array;
}
