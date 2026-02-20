<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Pre-aggregated daily statistics for a site.
 */
#[Api(since: '1.0.0')]
final readonly class DailyStats
{
    public function __construct(
        public string $siteId,
        public DateTimeImmutable $date,
        public int $visitors = 0,
        public int $pageviews = 0,
        public int $sessions = 0,
        public float $bounceRate = 0.0,
        public float $avgDuration = 0.0,
        public int $eventsCount = 0,
    ) {}
}
