<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Security\JustifiedAccess\JustificationRecord;
use Pulsar\Security\JustifiedAccess\JustificationStoreInterface;
use Pulsar\Security\JustifiedAccess\ReviewStatus;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function usort;

/**
 * In-memory justification store for testing and development.
 *
 * Production deployments should use a database-backed implementation.
 */
#[Api(since: '1.0.0')]
final class InMemoryJustificationStore implements JustificationStoreInterface
{
    /** @var array<string, JustificationRecord> */
    private array $records = [];

    #[Override]
    public function store(JustificationRecord $record): void
    {
        $this->records[$record->id] = $record;
    }

    #[Override]
    public function find(string $id): ?JustificationRecord
    {
        return $this->records[$id] ?? null;
    }

    #[Override]
    public function findByActor(string $actorId, int $limit = 50): array
    {
        return $this->queryAndLimit(
            static fn(JustificationRecord $r): bool => $r->actorId === $actorId,
            $limit,
        );
    }

    #[Override]
    public function findByResource(string $resourceType, string $resourceId, int $limit = 50): array
    {
        return $this->queryAndLimit(
            static fn(JustificationRecord $r): bool => $r->resourceType === $resourceType && $r->resourceId === $resourceId,
            $limit,
        );
    }

    #[Override]
    public function findByReviewStatus(ReviewStatus $status, int $limit = 50): array
    {
        return $this->queryAndLimit(
            static fn(JustificationRecord $r): bool => $r->reviewStatus === $status,
            $limit,
        );
    }

    #[Override]
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 100): array
    {
        return $this->queryAndLimit(
            static fn(JustificationRecord $r): bool => $r->accessTimestamp >= $from && $r->accessTimestamp <= $to,
            $limit,
        );
    }

    #[Override]
    public function updateReviewStatus(string $id, ReviewStatus $status): void
    {
        $existing = $this->records[$id] ?? null;

        if ($existing === null) {
            return;
        }

        $this->records[$id] = new JustificationRecord(
            id: $existing->id,
            actorId: $existing->actorId,
            actorName: $existing->actorName,
            actorRole: $existing->actorRole,
            resourceType: $existing->resourceType,
            resourceId: $existing->resourceId,
            category: $existing->category,
            justificationText: $existing->justificationText,
            dataClassification: $existing->dataClassification,
            accessTimestamp: $existing->accessTimestamp,
            sessionId: $existing->sessionId,
            ipAddress: $existing->ipAddress,
            supervisorApproval: $existing->supervisorApproval,
            reviewStatus: $status,
            breakTheGlass: $existing->breakTheGlass,
            metadata: $existing->metadata,
        );
    }

    #[Override]
    public function countUniqueResourcesByActor(string $actorId, DateTimeImmutable $since): int
    {
        $uniqueResources = [];

        foreach ($this->records as $record) {
            if ($record->actorId === $actorId && $record->accessTimestamp >= $since) {
                $key = $record->resourceType . ':' . $record->resourceId;
                $uniqueResources[$key] = true;
            }
        }

        return count($uniqueResources);
    }

    #[Override]
    public function findActiveBreakTheGlass(string $actorId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(JustificationRecord $r): bool => $r->actorId === $actorId && $r->breakTheGlass,
        ));
    }

    /**
     * @param callable(JustificationRecord): bool $predicate
     * @return list<JustificationRecord>
     */
    private function queryAndLimit(callable $predicate, int $limit): array
    {
        $results = array_values(array_filter($this->records, $predicate));

        usort(
            $results,
            static fn(JustificationRecord $a, JustificationRecord $b): int => $b->accessTimestamp <=> $a->accessTimestamp,
        );

        return array_slice($results, 0, $limit);
    }
}
