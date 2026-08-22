<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess\Internal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\JustifiedAccess\Internal\InMemoryJustificationStore;
use Pulsar\Security\JustifiedAccess\JustificationCategory;
use Pulsar\Security\JustifiedAccess\JustificationRecord;
use Pulsar\Security\JustifiedAccess\ReviewStatus;

#[CoversClass(InMemoryJustificationStore::class)]
final class InMemoryJustificationStoreTest extends TestCase
{
    private InMemoryJustificationStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryJustificationStore();
    }

    #[Test]
    public function storeAndFindRecord(): void
    {
        $record = $this->createRecord('rec-1', 'actor-1');

        $this->store->store($record);

        $found = $this->store->find('rec-1');
        self::assertNotNull($found);
        self::assertSame('rec-1', $found->id);
        self::assertSame('actor-1', $found->actorId);
    }

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        self::assertNull($this->store->find('nonexistent'));
    }

    #[Test]
    public function findByActorReturnsMatchingRecords(): void
    {
        $this->store->store($this->createRecord('r1', 'alice', accessTime: '2026-01-03T00:00:00+00:00'));
        $this->store->store($this->createRecord('r2', 'bob', accessTime: '2026-01-02T00:00:00+00:00'));
        $this->store->store($this->createRecord('r3', 'alice', accessTime: '2026-01-01T00:00:00+00:00'));

        $results = $this->store->findByActor('alice');

        self::assertCount(2, $results);
        self::assertSame('r1', $results[0]->id);
        self::assertSame('r3', $results[1]->id);
    }

    #[Test]
    public function findByActorRespectsLimit(): void
    {
        $this->store->store($this->createRecord('r1', 'alice', accessTime: '2026-01-03T00:00:00+00:00'));
        $this->store->store($this->createRecord('r2', 'alice', accessTime: '2026-01-02T00:00:00+00:00'));
        $this->store->store($this->createRecord('r3', 'alice', accessTime: '2026-01-01T00:00:00+00:00'));

        $results = $this->store->findByActor('alice', limit: 2);

        self::assertCount(2, $results);
    }

    #[Test]
    public function findByActorReturnsMostRecentFirst(): void
    {
        $this->store->store($this->createRecord('old', 'actor-1', accessTime: '2025-01-01T00:00:00+00:00'));
        $this->store->store($this->createRecord('new', 'actor-1', accessTime: '2026-06-01T00:00:00+00:00'));

        $results = $this->store->findByActor('actor-1');

        self::assertSame('new', $results[0]->id);
        self::assertSame('old', $results[1]->id);
    }

    #[Test]
    public function findByResourceReturnsMatchingRecords(): void
    {
        $this->store->store($this->createRecord('r1', 'a', resourceType: 'patient', resourceId: 'p-1'));
        $this->store->store($this->createRecord('r2', 'b', resourceType: 'patient', resourceId: 'p-2'));
        $this->store->store($this->createRecord('r3', 'c', resourceType: 'patient', resourceId: 'p-1'));

        $results = $this->store->findByResource('patient', 'p-1');

        self::assertCount(2, $results);
    }

    #[Test]
    public function findByReviewStatusReturnsMatchingRecords(): void
    {
        $this->store->store($this->createRecord('r1', 'a', reviewStatus: ReviewStatus::Pending));
        $this->store->store($this->createRecord('r2', 'b', reviewStatus: ReviewStatus::Approved));
        $this->store->store($this->createRecord('r3', 'c', reviewStatus: ReviewStatus::Pending));

        $results = $this->store->findByReviewStatus(ReviewStatus::Pending);

        self::assertCount(2, $results);
    }

    #[Test]
    public function findByDateRangeReturnsRecordsInWindow(): void
    {
        $this->store->store($this->createRecord('before', 'a', accessTime: '2025-12-31T00:00:00+00:00'));
        $this->store->store($this->createRecord('inside1', 'b', accessTime: '2026-01-15T00:00:00+00:00'));
        $this->store->store($this->createRecord('inside2', 'c', accessTime: '2026-02-01T00:00:00+00:00'));
        $this->store->store($this->createRecord('after', 'd', accessTime: '2026-03-01T00:00:00+00:00'));

        $results = $this->store->findByDateRange(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-02-28T23:59:59+00:00'),
        );

        self::assertCount(2, $results);
        self::assertSame('inside2', $results[0]->id);
        self::assertSame('inside1', $results[1]->id);
    }

    #[Test]
    public function updateReviewStatusChangesStatus(): void
    {
        $this->store->store($this->createRecord('r1', 'actor-1', reviewStatus: ReviewStatus::Pending));

        $this->store->updateReviewStatus('r1', ReviewStatus::Approved);

        $updated = $this->store->find('r1');
        self::assertNotNull($updated);
        self::assertSame(ReviewStatus::Approved, $updated->reviewStatus);
    }

    #[Test]
    public function updateReviewStatusPreservesOtherFields(): void
    {
        $record = $this->createRecord('r1', 'actor-1', reviewStatus: ReviewStatus::Pending);
        $this->store->store($record);

        $this->store->updateReviewStatus('r1', ReviewStatus::Flagged);

        $updated = $this->store->find('r1');
        self::assertNotNull($updated);
        self::assertSame('actor-1', $updated->actorId);
        self::assertSame($record->actorName, $updated->actorName);
        self::assertSame($record->resourceType, $updated->resourceType);
        self::assertSame($record->category, $updated->category);
        self::assertSame(ReviewStatus::Flagged, $updated->reviewStatus);
    }

    #[Test]
    public function updateReviewStatusIgnoresUnknownId(): void
    {
        $this->store->updateReviewStatus('nonexistent', ReviewStatus::Approved);

        self::assertNull($this->store->find('nonexistent'));
    }

    #[Test]
    public function countUniqueResourcesByActorCountsDistinctResources(): void
    {
        $since = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $this->store->store($this->createRecord('r1', 'actor-1', resourceType: 'patient', resourceId: 'p-1', accessTime: '2026-02-01T00:00:00+00:00'));
        $this->store->store($this->createRecord('r2', 'actor-1', resourceType: 'patient', resourceId: 'p-2', accessTime: '2026-02-02T00:00:00+00:00'));
        $this->store->store($this->createRecord('r3', 'actor-1', resourceType: 'patient', resourceId: 'p-1', accessTime: '2026-02-03T00:00:00+00:00'));
        $this->store->store($this->createRecord('r4', 'actor-1', resourceType: 'account', resourceId: 'a-1', accessTime: '2026-02-04T00:00:00+00:00'));
        $this->store->store($this->createRecord('r5', 'actor-2', resourceType: 'patient', resourceId: 'p-3', accessTime: '2026-02-05T00:00:00+00:00'));

        self::assertSame(3, $this->store->countUniqueResourcesByActor('actor-1', $since));
    }

    #[Test]
    public function countUniqueResourcesExcludesRecordsBeforeSince(): void
    {
        $since = new DateTimeImmutable('2026-03-01T00:00:00+00:00');

        $this->store->store($this->createRecord('r1', 'actor-1', resourceType: 'doc', resourceId: 'd-1', accessTime: '2026-01-01T00:00:00+00:00'));
        $this->store->store($this->createRecord('r2', 'actor-1', resourceType: 'doc', resourceId: 'd-2', accessTime: '2026-04-01T00:00:00+00:00'));

        self::assertSame(1, $this->store->countUniqueResourcesByActor('actor-1', $since));
    }

    #[Test]
    public function findActiveBreakTheGlassReturnsOnlyBtgRecords(): void
    {
        $this->store->store($this->createRecord('r1', 'actor-1', breakTheGlass: false));
        $this->store->store($this->createRecord('r2', 'actor-1', breakTheGlass: true));
        $this->store->store($this->createRecord('r3', 'actor-1', breakTheGlass: true));
        $this->store->store($this->createRecord('r4', 'actor-2', breakTheGlass: true));

        $results = $this->store->findActiveBreakTheGlass('actor-1');

        self::assertCount(2, $results);
        self::assertTrue($results[0]->breakTheGlass);
        self::assertTrue($results[1]->breakTheGlass);
    }

    #[Test]
    public function findActiveBreakTheGlassReturnsEmptyForNoMatches(): void
    {
        $this->store->store($this->createRecord('r1', 'actor-1', breakTheGlass: false));

        self::assertSame([], $this->store->findActiveBreakTheGlass('actor-1'));
    }

    #[Test]
    public function storeOverwritesExistingRecordWithSameId(): void
    {
        $this->store->store($this->createRecord('r1', 'actor-1'));
        $this->store->store($this->createRecord('r1', 'actor-2'));

        $found = $this->store->find('r1');
        self::assertNotNull($found);
        self::assertSame('actor-2', $found->actorId);
    }

    private function createRecord(
        string $id,
        string $actorId,
        string $resourceType = 'document',
        string $resourceId = 'doc-1',
        string $accessTime = '2026-01-15T10:00:00+00:00',
        ReviewStatus $reviewStatus = ReviewStatus::Pending,
        bool $breakTheGlass = false,
    ): JustificationRecord {
        return new JustificationRecord(
            id: $id,
            actorId: $actorId,
            actorName: 'Test User',
            actorRole: 'analyst',
            resourceType: $resourceType,
            resourceId: $resourceId,
            category: JustificationCategory::CustomerRequest,
            justificationText: 'Customer requested access to their data',
            dataClassification: DataClassification::Confidential,
            accessTimestamp: new DateTimeImmutable($accessTime),
            sessionId: 'sess-test',
            ipAddress: '192.168.1.1',
            supervisorApproval: null,
            reviewStatus: $reviewStatus,
            breakTheGlass: $breakTheGlass,
        );
    }
}
