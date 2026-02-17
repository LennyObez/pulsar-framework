<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\Segment;
use Pulsar\Extension\Analytics\Domain\SegmentFilter;

/**
 * Audience segmentation service.
 */
#[Api(since: '1.0.0')]
interface SegmentServiceInterface
{
    /**
     * Create a new segment.
     *
     * @param list<SegmentFilter> $filters
     */
    public function create(string $siteId, string $name, array $filters): Segment;

    /**
     * Get a segment by ID.
     */
    public function findById(string $id): ?Segment;

    /**
     * List all segments for a site.
     *
     * @return list<Segment>
     */
    public function listForSite(string $siteId): array;

    /**
     * Delete a segment.
     */
    public function delete(string $id): void;

    /**
     * Count visitors matching a segment in a date range.
     */
    public function countVisitors(
        string $segmentId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int;
}
