<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\JustifiedAccess\AccessReviewService;
use Pulsar\Security\JustifiedAccess\JustificationCategory;
use Pulsar\Security\JustifiedAccess\JustificationRecord;
use Pulsar\Security\JustifiedAccess\JustificationStoreInterface;
use Pulsar\Security\JustifiedAccess\ReviewStatus;

#[CoversClass(AccessReviewService::class)]
final class AccessReviewServiceTest extends TestCase
{
    private JustificationStoreInterface&Stub $store;
    private AuditLoggerInterface&Stub $auditLogger;
    private AccessReviewService $service;

    protected function setUp(): void
    {
        $this->store = $this->createStub(JustificationStoreInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->service = new AccessReviewService($this->store, $this->auditLogger);
    }

    private function createRecord(
        string $id = 'rec-1',
        ReviewStatus $reviewStatus = ReviewStatus::Pending,
        bool $breakTheGlass = false,
    ): JustificationRecord {
        return new JustificationRecord(
            id: $id,
            actorId: 'actor-1',
            actorName: 'John Doe',
            actorRole: 'analyst',
            resourceType: 'customer_record',
            resourceId: 'cust-42',
            category: JustificationCategory::CustomerRequest,
            justificationText: 'Customer requested account statement',
            dataClassification: DataClassification::Confidential,
            accessTimestamp: new DateTimeImmutable('2026-03-15T10:00:00+00:00'),
            sessionId: 'sess-1',
            ipAddress: '10.0.0.1',
            supervisorApproval: null,
            reviewStatus: $reviewStatus,
            breakTheGlass: $breakTheGlass,
        );
    }

    public function testPendingReviewsDelegatesToStore(): void
    {
        $records = [$this->createRecord()];
        $this->store->method('findByReviewStatus')->willReturn($records);

        $result = $this->service->pendingReviews(25);

        self::assertCount(1, $result);
        self::assertSame('rec-1', $result[0]->id);
    }

    public function testFlaggedRecordsDelegatesToStore(): void
    {
        $records = [$this->createRecord(reviewStatus: ReviewStatus::Flagged)];
        $this->store->method('findByReviewStatus')->willReturn($records);

        $result = $this->service->flaggedRecords(10);

        self::assertCount(1, $result);
    }

    public function testRecordsByActorDelegatesToStore(): void
    {
        $records = [$this->createRecord(), $this->createRecord(id: 'rec-2')];
        $this->store->method('findByActor')->willReturn($records);

        $result = $this->service->recordsByActor('actor-1');

        self::assertCount(2, $result);
    }

    public function testRecordsByResourceDelegatesToStore(): void
    {
        $records = [$this->createRecord()];
        $this->store->method('findByResource')->willReturn($records);

        $result = $this->service->recordsByResource('customer_record', 'cust-42');

        self::assertCount(1, $result);
    }

    public function testRecordsByDateRangeDelegatesToStore(): void
    {
        $from = new DateTimeImmutable('2026-03-01');
        $to = new DateTimeImmutable('2026-03-31');
        $records = [$this->createRecord()];
        $this->store->method('findByDateRange')->willReturn($records);

        $result = $this->service->recordsByDateRange($from, $to);

        self::assertCount(1, $result);
    }

    public function testMarkReviewedUpdatesStatusAndLogs(): void
    {
        $record = $this->createRecord(reviewStatus: ReviewStatus::Pending);
        $store = $this->createMock(JustificationStoreInterface::class);
        $store->method('find')->willReturn($record);
        $store->expects(self::once())
            ->method('updateReviewStatus')
            ->with('rec-1', ReviewStatus::Reviewed);

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log')->willReturn($auditEntry);

        $service = new AccessReviewService($store, $auditLogger);
        $service->markReviewed('rec-1', 'reviewer-1');
    }

    public function testApproveUpdatesStatus(): void
    {
        $record = $this->createRecord(reviewStatus: ReviewStatus::Pending);
        $store = $this->createMock(JustificationStoreInterface::class);
        $store->method('find')->willReturn($record);
        $store->expects(self::once())
            ->method('updateReviewStatus')
            ->with('rec-1', ReviewStatus::Approved);

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn($auditEntry);

        $service = new AccessReviewService($store, $auditLogger);
        $service->approve('rec-1', 'reviewer-1');
    }

    public function testFlagUpdatesStatus(): void
    {
        $record = $this->createRecord(reviewStatus: ReviewStatus::Reviewed);
        $store = $this->createMock(JustificationStoreInterface::class);
        $store->method('find')->willReturn($record);
        $store->expects(self::once())
            ->method('updateReviewStatus')
            ->with('rec-1', ReviewStatus::Flagged);

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn($auditEntry);

        $service = new AccessReviewService($store, $auditLogger);
        $service->flag('rec-1', 'reviewer-1');
    }

    public function testEscalateUpdatesStatus(): void
    {
        $record = $this->createRecord(reviewStatus: ReviewStatus::Flagged);
        $store = $this->createMock(JustificationStoreInterface::class);
        $store->method('find')->willReturn($record);
        $store->expects(self::once())
            ->method('updateReviewStatus')
            ->with('rec-1', ReviewStatus::Escalated);

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn($auditEntry);

        $service = new AccessReviewService($store, $auditLogger);
        $service->escalate('rec-1', 'reviewer-1');
    }

    public function testUpdateStatusSkipsWhenRecordNotFound(): void
    {
        $store = $this->createMock(JustificationStoreInterface::class);
        $store->method('find')->willReturn(null);
        $store->expects(self::never())->method('updateReviewStatus');

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('log');

        $service = new AccessReviewService($store, $auditLogger);
        $service->approve('nonexistent', 'reviewer-1');
    }

    public function testGenerateSummary(): void
    {
        $records = [
            $this->createRecord(id: 'r1', reviewStatus: ReviewStatus::Pending),
            $this->createRecord(id: 'r2', reviewStatus: ReviewStatus::Approved),
            $this->createRecord(id: 'r3', reviewStatus: ReviewStatus::Flagged, breakTheGlass: true),
            $this->createRecord(id: 'r4', reviewStatus: ReviewStatus::Reviewed),
            $this->createRecord(id: 'r5', reviewStatus: ReviewStatus::Escalated, breakTheGlass: true),
        ];
        $this->store->method('findByDateRange')->willReturn($records);

        $from = new DateTimeImmutable('2026-03-01');
        $to = new DateTimeImmutable('2026-03-31');

        $summary = $this->service->generateSummary($from, $to);

        self::assertSame(5, $summary['total']);
        self::assertSame(1, $summary['pending']);
        self::assertSame(1, $summary['approved']);
        self::assertSame(1, $summary['flagged']);
        self::assertSame(1, $summary['reviewed']);
        self::assertSame(1, $summary['escalated']);
        self::assertSame(2, $summary['break_the_glass']);
    }

    public function testGenerateSummaryEmptyRecords(): void
    {
        $this->store->method('findByDateRange')->willReturn([]);

        $from = new DateTimeImmutable('2026-03-01');
        $to = new DateTimeImmutable('2026-03-31');

        $summary = $this->service->generateSummary($from, $to);

        self::assertSame(0, $summary['total']);
        self::assertSame(0, $summary['break_the_glass']);
    }
}
