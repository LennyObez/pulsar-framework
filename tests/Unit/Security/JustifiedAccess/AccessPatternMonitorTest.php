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
use Pulsar\Security\Incident\IncidentInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\JustifiedAccess\AccessPatternMonitor;
use Pulsar\Security\JustifiedAccess\JustificationCategory;
use Pulsar\Security\JustifiedAccess\JustificationRecord;
use Pulsar\Security\JustifiedAccess\JustificationStoreInterface;
use Pulsar\Security\JustifiedAccess\JustifiedAccessConfig;
use Pulsar\Security\JustifiedAccess\ReviewStatus;
use Pulsar\Testing\Clock\ClockInterface;

#[CoversClass(AccessPatternMonitor::class)]
final class AccessPatternMonitorTest extends TestCase
{
    private JustificationStoreInterface&Stub $store;
    private AuditLoggerInterface&Stub $auditLogger;
    private IncidentReporterInterface&Stub $incidentReporter;
    private ClockInterface&Stub $clock;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-03-15T12:00:00+00:00');
        $this->store = $this->createStub(JustificationStoreInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->incidentReporter = $this->createStub(IncidentReporterInterface::class);
        $this->clock = $this->createStub(ClockInterface::class);
        $this->clock->method('now')->willReturn($this->now);
    }

    private function createMonitor(int $threshold = 50, int $windowSeconds = 3600): AccessPatternMonitor
    {
        return new AccessPatternMonitor(
            new JustifiedAccessConfig(anomalyThreshold: $threshold, anomalyWindowSeconds: $windowSeconds),
            $this->store,
            $this->auditLogger,
            $this->incidentReporter,
            $this->clock,
        );
    }

    public function testIsAnomalousReturnsFalseWhenBelowThreshold(): void
    {
        $this->store->method('countUniqueResourcesByActor')->willReturn(10);

        $monitor = $this->createMonitor(threshold: 50);

        self::assertFalse($monitor->isAnomalous('actor-1'));
    }

    public function testIsAnomalousReturnsTrueWhenAtThreshold(): void
    {
        $this->store->method('countUniqueResourcesByActor')->willReturn(50);

        $monitor = $this->createMonitor(threshold: 50);

        self::assertTrue($monitor->isAnomalous('actor-1'));
    }

    public function testIsAnomalousReturnsTrueWhenAboveThreshold(): void
    {
        $this->store->method('countUniqueResourcesByActor')->willReturn(100);

        $monitor = $this->createMonitor(threshold: 50);

        self::assertTrue($monitor->isAnomalous('actor-1'));
    }

    public function testEvaluateReturnsFalseWhenNotAnomalous(): void
    {
        $this->store->method('countUniqueResourcesByActor')->willReturn(5);

        $monitor = $this->createMonitor(threshold: 50);

        self::assertFalse($monitor->evaluate('actor-1'));
    }

    public function testEvaluateReturnsTrueAndReportsWhenAnomalous(): void
    {
        $this->store->method('countUniqueResourcesByActor')->willReturn(60);

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->willReturn($auditEntry);

        $incident = $this->createStub(IncidentInterface::class);
        $incidentReporter = $this->createMock(IncidentReporterInterface::class);
        $incidentReporter->expects(self::once())
            ->method('report')
            ->willReturn($incident);

        $monitor = new AccessPatternMonitor(
            new JustifiedAccessConfig(anomalyThreshold: 50, anomalyWindowSeconds: 3600),
            $this->store,
            $auditLogger,
            $incidentReporter,
            $this->clock,
        );

        self::assertTrue($monitor->evaluate('actor-1'));
    }

    public function testCurrentAccessCountDelegatesToStore(): void
    {
        $this->store->method('countUniqueResourcesByActor')->willReturn(25);

        $monitor = $this->createMonitor();

        self::assertSame(25, $monitor->currentAccessCount('actor-1'));
    }

    public function testActiveBreakTheGlassSessionsFiltersExpired(): void
    {
        $activeRecord = new JustificationRecord(
            id: 'r1',
            actorId: 'actor-1',
            actorName: 'Dr. Smith',
            actorRole: 'physician',
            resourceType: 'patient_record',
            resourceId: 'patient-42',
            category: JustificationCategory::Emergency,
            justificationText: 'Emergency access required',
            dataClassification: DataClassification::Restricted,
            accessTimestamp: $this->now->modify('-5 minutes'),
            sessionId: 'sess-1',
            ipAddress: '10.0.0.1',
            supervisorApproval: null,
            reviewStatus: ReviewStatus::Flagged,
            breakTheGlass: true,
        );

        $expiredRecord = new JustificationRecord(
            id: 'r2',
            actorId: 'actor-1',
            actorName: 'Dr. Smith',
            actorRole: 'physician',
            resourceType: 'patient_record',
            resourceId: 'patient-99',
            category: JustificationCategory::Emergency,
            justificationText: 'Previous emergency',
            dataClassification: DataClassification::Restricted,
            accessTimestamp: $this->now->modify('-2 hours'),
            sessionId: 'sess-2',
            ipAddress: '10.0.0.1',
            supervisorApproval: null,
            reviewStatus: ReviewStatus::Flagged,
            breakTheGlass: true,
        );

        $this->store->method('findActiveBreakTheGlass')->willReturn([$activeRecord, $expiredRecord]);

        $monitor = $this->createMonitor();
        $active = $monitor->activeBreakTheGlassSessions('actor-1');

        // Only the recent record (5 min ago) should be active with default 900s (15 min) duration
        self::assertCount(1, $active);
        self::assertSame('r1', $active[0]->id);
    }

    public function testActiveBreakTheGlassSessionsReturnsEmptyWhenNone(): void
    {
        $this->store->method('findActiveBreakTheGlass')->willReturn([]);

        $monitor = $this->createMonitor();

        self::assertSame([], $monitor->activeBreakTheGlassSessions('actor-1'));
    }
}
