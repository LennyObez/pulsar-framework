<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Hourly rollup statistics, retained for 48 hours then merged into daily.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HourlyStats
{
    public function __construct(
        public string $siteId,
        public DateTimeImmutable $date,
        public int $hour,
        public int $visitors = 0,
        public int $pageviews = 0,
        public int $sessions = 0,
        public float $bounceRate = 0.0,
        public float $avgDuration = 0.0,
        public int $eventsCount = 0,
    ) {}
}
