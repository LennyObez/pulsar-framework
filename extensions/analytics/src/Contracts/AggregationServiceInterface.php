<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Aggregates raw analytics data into hourly and daily statistics.
 */
#[Api(since: '1.0.0')]
interface AggregationServiceInterface
{
    /**
     * Aggregate raw page views for the hour containing the given timestamp.
     */
    public function aggregateHourly(DateTimeImmutable $hour, string $siteId): void;

    /**
     * Aggregate raw page views for the day containing the given timestamp.
     */
    public function aggregateDaily(DateTimeImmutable $day, string $siteId): void;
}
