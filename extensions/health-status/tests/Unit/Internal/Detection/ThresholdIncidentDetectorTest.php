<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Internal\Detection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;
use Pulsar\Extension\HealthStatus\Internal\Detection\ThresholdIncidentDetector;
use Pulsar\Resilience\HealthCheck\HealthStatus;

#[CoversClass(ThresholdIncidentDetector::class)]
final class ThresholdIncidentDetectorTest extends TestCase
{
    #[Test]
    public function noIncidentsWhenAllHealthy(): void
    {
        $detector = new ThresholdIncidentDetector(threshold: 3);
        $store = $this->createStoreWithSnapshots($this->healthySnapshots(5));

        $snapshot = $this->createSnapshot(HealthStatus::Healthy, [
            ['name' => 'database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 1.0],
        ]);

        $incidents = $detector->detect($snapshot, $store);

        self::assertSame([], $incidents);
    }

    #[Test]
    public function incidentTriggeredAfterConsecutiveFailures(): void
    {
        $detector = new ThresholdIncidentDetector(threshold: 3);

        // Build history with 2 prior unhealthy snapshots for 'database'
        $priorSnapshots = [
            $this->createSnapshot(HealthStatus::Unhealthy, [
                ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
            ], '2026-03-27T10:01:00Z'),
            $this->createSnapshot(HealthStatus::Unhealthy, [
                ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
            ], '2026-03-27T10:02:00Z'),
        ];

        $store = $this->createStoreWithSnapshots($priorSnapshots);

        // This is the 3rd consecutive failure
        $currentSnapshot = $this->createSnapshot(HealthStatus::Unhealthy, [
            ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
        ]);

        $incidents = $detector->detect($currentSnapshot, $store);

        self::assertCount(1, $incidents);
        self::assertSame('database', $incidents[0]->checkName);
        self::assertSame(IncidentStatus::Open, $incidents[0]->status);
        self::assertSame(IncidentSeverity::Major, $incidents[0]->severity);
    }

    #[Test]
    public function noIncidentBelowThreshold(): void
    {
        $detector = new ThresholdIncidentDetector(threshold: 3);

        // Only 1 prior failure
        $priorSnapshots = [
            $this->createSnapshot(HealthStatus::Unhealthy, [
                ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
            ], '2026-03-27T10:01:00Z'),
        ];

        $store = $this->createStoreWithSnapshots($priorSnapshots);

        // 2nd failure (still below threshold of 3)
        $currentSnapshot = $this->createSnapshot(HealthStatus::Unhealthy, [
            ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
        ]);

        $incidents = $detector->detect($currentSnapshot, $store);

        self::assertSame([], $incidents);
    }

    #[Test]
    public function degradedCheckProducesMinorSeverity(): void
    {
        $detector = new ThresholdIncidentDetector(threshold: 2);

        $priorSnapshots = [
            $this->createSnapshot(HealthStatus::Degraded, [
                ['name' => 'cache', 'status' => 'degraded', 'message' => 'Slow', 'latency_ms' => 100.0],
            ], '2026-03-27T10:01:00Z'),
        ];

        $store = $this->createStoreWithSnapshots($priorSnapshots);

        $currentSnapshot = $this->createSnapshot(HealthStatus::Degraded, [
            ['name' => 'cache', 'status' => 'degraded', 'message' => 'Slow', 'latency_ms' => 110.0],
        ]);

        $incidents = $detector->detect($currentSnapshot, $store);

        self::assertCount(1, $incidents);
        self::assertSame(IncidentSeverity::Minor, $incidents[0]->severity);
    }

    #[Test]
    public function recoveryAutoResolvesActiveIncident(): void
    {
        $detector = new ThresholdIncidentDetector(threshold: 3);

        $activeIncident = new Incident(
            id: 'inc-active',
            checkName: 'database',
            severity: IncidentSeverity::Major,
            status: IncidentStatus::Open,
            message: 'Down',
            startedAt: new DateTimeImmutable('2026-03-27T10:00:00Z'),
        );

        $store = $this->createStoreWithSnapshots([], [$activeIncident]);

        // Current snapshot shows healthy database
        $currentSnapshot = $this->createSnapshot(HealthStatus::Healthy, [
            ['name' => 'database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 1.0],
        ]);

        $incidents = $detector->detect($currentSnapshot, $store);

        // Should return resolved version of the incident
        self::assertCount(1, $incidents);
        self::assertSame(IncidentStatus::Resolved, $incidents[0]->status);
        self::assertSame('inc-active', $incidents[0]->id);
        self::assertNotNull($incidents[0]->resolvedAt);
    }

    #[Test]
    public function multipleChecksCanProduceMultipleIncidents(): void
    {
        $detector = new ThresholdIncidentDetector(threshold: 2);

        $priorSnapshots = [
            $this->createSnapshot(HealthStatus::Unhealthy, [
                ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
                ['name' => 'cache', 'status' => 'degraded', 'message' => 'Slow', 'latency_ms' => 200.0],
            ], '2026-03-27T10:01:00Z'),
        ];

        $store = $this->createStoreWithSnapshots($priorSnapshots);

        $currentSnapshot = $this->createSnapshot(HealthStatus::Unhealthy, [
            ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
            ['name' => 'cache', 'status' => 'degraded', 'message' => 'Slow', 'latency_ms' => 210.0],
        ]);

        $incidents = $detector->detect($currentSnapshot, $store);

        self::assertCount(2, $incidents);
        $checkNames = array_map(static fn(Incident $i): string => $i->checkName, $incidents);
        self::assertContains('database', $checkNames);
        self::assertContains('cache', $checkNames);
    }

    #[Test]
    public function doesNotDuplicateIncidentForAlreadyTrackedCheck(): void
    {
        $detector = new ThresholdIncidentDetector(threshold: 2);

        $existingIncident = new Incident(
            id: 'inc-existing',
            checkName: 'database',
            severity: IncidentSeverity::Major,
            status: IncidentStatus::Open,
            message: 'Down',
            startedAt: new DateTimeImmutable('2026-03-27T09:00:00Z'),
        );

        $priorSnapshots = [
            $this->createSnapshot(HealthStatus::Unhealthy, [
                ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
            ], '2026-03-27T10:01:00Z'),
        ];

        $store = $this->createStoreWithSnapshots($priorSnapshots, [$existingIncident]);

        $currentSnapshot = $this->createSnapshot(HealthStatus::Unhealthy, [
            ['name' => 'database', 'status' => 'unhealthy', 'message' => 'Down', 'latency_ms' => 0.0],
        ]);

        $incidents = $detector->detect($currentSnapshot, $store);

        // No new incident because one already exists for this check
        self::assertSame([], $incidents);
    }

    /**
     * @param list<HealthSnapshot> $snapshots
     * @param list<Incident> $activeIncidents
     */
    private function createStoreWithSnapshots(
        array $snapshots,
        array $activeIncidents = [],
    ): HealthHistoryStoreInterface {
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn($snapshots);
        $store->method('activeIncidents')->willReturn($activeIncidents);

        return $store;
    }

    /**
     * @param list<array{name: string, status: string, message: string, latency_ms: float}> $results
     */
    private function createSnapshot(
        HealthStatus $overall,
        array $results,
        string $time = '2026-03-27T10:03:00Z',
    ): HealthSnapshot {
        /** @var int $counter */
        static $counter = 0;
        $counter++;

        return new HealthSnapshot(
            id: "snap-{$counter}",
            overallStatus: $overall,
            results: $results,
            totalDurationMs: 1.0,
            capturedAt: new DateTimeImmutable($time),
        );
    }

    /**
     * @return list<HealthSnapshot>
     */
    private function healthySnapshots(int $count): array
    {
        $snapshots = [];

        for ($i = 0; $i < $count; $i++) {
            $snapshots[] = new HealthSnapshot(
                id: "healthy-{$i}",
                overallStatus: HealthStatus::Healthy,
                results: [['name' => 'database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 1.0]],
                totalDurationMs: 1.0,
                capturedAt: new DateTimeImmutable("2026-03-27T10:0{$i}:00Z"),
            );
        }

        return $snapshots;
    }
}
