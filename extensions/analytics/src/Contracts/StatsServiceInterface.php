<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;

/**
 * Query aggregated analytics statistics.
 * @api
 */
#[Api(since: '1.0.0')]
interface StatsServiceInterface
{
    /**
     * Get aggregate metrics for a site within a date range.
     *
     * @param array<string, string> $filters Optional dimension filters (e.g., ['page' => '/about'])
     * @return array{visitors: int, pageviews: int, sessions: int, bounce_rate: float, avg_duration: float, events_count: int}
     */
    public function getAggregate(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $filters = [],
    ): array;

    /**
     * Get time-series data points for a metric.
     *
     * @return list<array{date: string, value: int|float}>
     */
    public function getTimeseries(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $metric,
        string $interval = 'day',
    ): array;

    /**
     * Get top-N breakdown for a dimension.
     *
     * @return list<array{name: string, visitors: int, pageviews: int}>
     */
    public function getBreakdown(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        BreakdownDimension $dimension,
        int $limit = 10,
    ): array;

    /**
     * Get real-time visitor count and active pages.
     *
     * @return array{current_visitors: int, active_pages: list<array{pathname: string, visitors: int}>}
     */
    public function getRealtime(string $siteId): array;

    /**
     * Get aggregate metrics with comparison to a previous period.
     *
     * Returns current period metrics and percentage change from previous period.
     *
     * @param array<string, string> $filters Optional dimension filters
     * @return array{
     *     current: array{visitors: int, pageviews: int, sessions: int, bounce_rate: float, avg_duration: float, events_count: int},
     *     previous: array{visitors: int, pageviews: int, sessions: int, bounce_rate: float, avg_duration: float, events_count: int},
     *     change: array{visitors: float, pageviews: float, sessions: float, bounce_rate: float, avg_duration: float, events_count: float},
     * }
     */
    public function getAggregateWithComparison(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $previousFrom,
        DateTimeImmutable $previousTo,
        array $filters = [],
    ): array;
}
