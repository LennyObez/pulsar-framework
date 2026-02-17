<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * An audience segment definition for filtering analytics data.
 */
#[Api(since: '1.0.0')]
final readonly class Segment
{
    /**
     * @param string $id Unique segment identifier
     * @param string $siteId Site this segment belongs to
     * @param string $name Human-readable segment name
     * @param list<SegmentFilter> $filters Filters defining segment membership
     */
    public function __construct(
        public string $id,
        public string $siteId,
        public string $name,
        public array $filters,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}
}
