<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Persistence contract for justification records.
 *
 * Implementations provide storage and querying of access justifications
 * for compliance review workflows.
 * @api
 */
#[Api(since: '1.0.0')]
interface JustificationStoreInterface
{
    /**
     * Store a justification record.
     */
    public function store(JustificationRecord $record): void;

    /**
     * Retrieve a justification record by ID.
     */
    public function find(string $id): ?JustificationRecord;

    /**
     * Query records by actor.
     *
     * @return list<JustificationRecord>
     */
    public function findByActor(string $actorId, int $limit = 50): array;

    /**
     * Query records by resource.
     *
     * @return list<JustificationRecord>
     */
    public function findByResource(string $resourceType, string $resourceId, int $limit = 50): array;

    /**
     * Query records by review status.
     *
     * @return list<JustificationRecord>
     */
    public function findByReviewStatus(ReviewStatus $status, int $limit = 50): array;

    /**
     * Query records within a date range.
     *
     * @return list<JustificationRecord>
     */
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 100): array;

    /**
     * Update the review status of a record.
     */
    public function updateReviewStatus(string $id, ReviewStatus $status): void;

    /**
     * Count unique resources accessed by an actor within a time window.
     *
     * Used by AccessPatternMonitor for anomaly detection.
     */
    public function countUniqueResourcesByActor(string $actorId, DateTimeImmutable $since): int;

    /**
     * Find break-the-glass records that are still within their active window.
     *
     * @return list<JustificationRecord>
     */
    public function findActiveBreakTheGlass(string $actorId): array;
}
