<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Internal\Storage;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;
use Pulsar\Extension\HealthStatus\Internal\Storage\DatabaseHealthHistoryStore;
use Pulsar\Resilience\HealthCheck\HealthStatus;

use function sprintf;

#[CoversClass(DatabaseHealthHistoryStore::class)]
final class DatabaseHealthHistoryStoreTest extends TestCase
{
    private PdoConnection $connection;
    private DatabaseHealthHistoryStore $store;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
            options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->createTables();
        $this->store = new DatabaseHealthHistoryStore($this->connection);
    }

    #[Test]
    public function storeSnapshotPersistsData(): void
    {
        $snapshot = $this->createSnapshot('snap-001', HealthStatus::Healthy, 5.0);

        $this->store->storeSnapshot($snapshot);

        $result = $this->connection->query(
            'SELECT * FROM health_check_history WHERE id = :id',
            [':id' => 'snap-001'],
        );

        self::assertSame(1, $result->rowCount);
        $row = $result->first();
        self::assertNotNull($row);
        self::assertSame('snap-001', $row->getString('id'));
        self::assertSame('healthy', $row->getString('overall_status'));
    }

    #[Test]
    public function recentSnapshotsReturnsOrderedByDateDesc(): void
    {
        $this->store->storeSnapshot(
            $this->createSnapshot('snap-a', HealthStatus::Healthy, 1.0, '2026-03-27T10:00:00Z'),
        );
        $this->store->storeSnapshot(
            $this->createSnapshot('snap-b', HealthStatus::Degraded, 2.0, '2026-03-27T11:00:00Z'),
        );
        $this->store->storeSnapshot(
            $this->createSnapshot('snap-c', HealthStatus::Unhealthy, 3.0, '2026-03-27T12:00:00Z'),
        );

        $recent = $this->store->recentSnapshots(2);

        self::assertCount(2, $recent);
        self::assertSame('snap-c', $recent[0]->id);
        self::assertSame('snap-b', $recent[1]->id);
    }

    #[Test]
    public function recentSnapshotsDefaultLimitIs50(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->store->storeSnapshot(
                $this->createSnapshot(
                    "snap-{$i}",
                    HealthStatus::Healthy,
                    1.0,
                    '2026-03-27T' . sprintf('%02d', $i % 24) . ':' . sprintf('%02d', $i % 60) . ':00Z',
                ),
            );
        }

        $recent = $this->store->recentSnapshots();

        self::assertCount(50, $recent);
    }

    #[Test]
    public function snapshotsBetweenFiltersCorrectly(): void
    {
        $this->store->storeSnapshot(
            $this->createSnapshot('snap-before', HealthStatus::Healthy, 1.0, '2026-03-26T23:00:00Z'),
        );
        $this->store->storeSnapshot(
            $this->createSnapshot('snap-in', HealthStatus::Healthy, 1.0, '2026-03-27T06:00:00Z'),
        );
        $this->store->storeSnapshot(
            $this->createSnapshot('snap-after', HealthStatus::Healthy, 1.0, '2026-03-28T01:00:00Z'),
        );

        $from = new DateTimeImmutable('2026-03-27T00:00:00Z');
        $to = new DateTimeImmutable('2026-03-27T23:59:59Z');

        $snapshots = $this->store->snapshotsBetween($from, $to);

        self::assertCount(1, $snapshots);
        self::assertSame('snap-in', $snapshots[0]->id);
    }

    #[Test]
    public function deleteSnapshotsOlderThanRemovesCorrectRows(): void
    {
        $this->store->storeSnapshot(
            $this->createSnapshot('snap-old', HealthStatus::Healthy, 1.0, '2026-03-01T00:00:00Z'),
        );
        $this->store->storeSnapshot(
            $this->createSnapshot('snap-new', HealthStatus::Healthy, 1.0, '2026-03-27T12:00:00Z'),
        );

        $cutoff = new DateTimeImmutable('2026-03-15T00:00:00Z');
        $deleted = $this->store->deleteSnapshotsOlderThan($cutoff);

        self::assertSame(1, $deleted);
        self::assertSame(1, $this->store->snapshotCount());
    }

    #[Test]
    public function snapshotCountReturnsCorrectTotal(): void
    {
        self::assertSame(0, $this->store->snapshotCount());

        $this->store->storeSnapshot($this->createSnapshot('snap-1', HealthStatus::Healthy, 1.0));
        $this->store->storeSnapshot($this->createSnapshot('snap-2', HealthStatus::Healthy, 1.0));

        self::assertSame(2, $this->store->snapshotCount());
    }

    #[Test]
    public function storeIncidentPersistsData(): void
    {
        $incident = $this->createIncident('inc-001', 'database', IncidentSeverity::Major);

        $this->store->storeIncident($incident);

        $result = $this->connection->query(
            'SELECT * FROM health_incidents WHERE id = :id',
            [':id' => 'inc-001'],
        );

        self::assertSame(1, $result->rowCount);
        $row = $result->first();
        self::assertNotNull($row);
        self::assertSame('database', $row->getString('check_name'));
        self::assertSame('major', $row->getString('severity'));
    }

    #[Test]
    public function updateIncidentModifiesExistingRecord(): void
    {
        $incident = $this->createIncident('inc-002', 'cache', IncidentSeverity::Minor);
        $this->store->storeIncident($incident);

        $resolved = $incident->resolve(new DateTimeImmutable('2026-03-27T12:00:00Z'));
        $this->store->updateIncident($resolved);

        $result = $this->connection->query(
            'SELECT * FROM health_incidents WHERE id = :id',
            [':id' => 'inc-002'],
        );

        $row = $result->first();
        self::assertNotNull($row);
        self::assertSame('resolved', $row->getString('status'));
        self::assertNotNull($row->getNullableString('resolved_at'));
    }

    #[Test]
    public function activeIncidentsReturnsOnlyOpenAndAcknowledged(): void
    {
        $open = $this->createIncident('inc-open', 'database', IncidentSeverity::Major);
        $this->store->storeIncident($open);

        $acked = $this->createIncident('inc-acked', 'cache', IncidentSeverity::Minor);
        $this->store->storeIncident($acked->acknowledge(new DateTimeImmutable('2026-03-27T10:05:00Z')));

        $resolved = $this->createIncident('inc-resolved', 'disk', IncidentSeverity::Critical);
        $this->store->storeIncident($resolved->resolve(new DateTimeImmutable('2026-03-27T11:00:00Z')));

        $active = $this->store->activeIncidents();

        self::assertCount(2, $active);
        $activeIds = array_map(static fn(Incident $i): string => $i->id, $active);
        self::assertContains('inc-open', $activeIds);
        self::assertContains('inc-acked', $activeIds);
    }

    #[Test]
    public function recentIncidentsReturnsOrderedByStartedAtDesc(): void
    {
        $this->store->storeIncident(
            $this->createIncident('inc-a', 'db', IncidentSeverity::Minor, '2026-03-27T08:00:00Z'),
        );
        $this->store->storeIncident(
            $this->createIncident('inc-b', 'cache', IncidentSeverity::Major, '2026-03-27T10:00:00Z'),
        );
        $this->store->storeIncident(
            $this->createIncident('inc-c', 'disk', IncidentSeverity::Critical, '2026-03-27T12:00:00Z'),
        );

        $recent = $this->store->recentIncidents(2);

        self::assertCount(2, $recent);
        self::assertSame('inc-c', $recent[0]->id);
        self::assertSame('inc-b', $recent[1]->id);
    }

    #[Test]
    public function storeAndRetrieveSnapshotPreservesJsonResults(): void
    {
        $results = [
            ['name' => 'database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 1.5],
            ['name' => 'cache', 'status' => 'degraded', 'message' => 'Slow', 'latency_ms' => 150.7],
        ];

        $snapshot = new HealthSnapshot(
            id: 'snap-json',
            overallStatus: HealthStatus::Degraded,
            results: $results,
            totalDurationMs: 152.2,
            capturedAt: new DateTimeImmutable('2026-03-27T12:00:00Z'),
        );

        $this->store->storeSnapshot($snapshot);
        $retrieved = $this->store->recentSnapshots(1);

        self::assertCount(1, $retrieved);
        self::assertCount(2, $retrieved[0]->results);
        self::assertSame('database', $retrieved[0]->results[0]['name']);
        self::assertSame('healthy', $retrieved[0]->results[0]['status']);
        self::assertSame('OK', $retrieved[0]->results[0]['message']);
        self::assertEqualsWithDelta(1.5, $retrieved[0]->results[0]['latency_ms'], 0.001);
        self::assertSame('cache', $retrieved[0]->results[1]['name']);
        self::assertSame('degraded', $retrieved[0]->results[1]['status']);
        self::assertEqualsWithDelta(150.7, $retrieved[0]->results[1]['latency_ms'], 0.001);
    }

    private function createSnapshot(
        string $id,
        HealthStatus $status,
        float $duration,
        string $time = '2026-03-27T12:00:00Z',
    ): HealthSnapshot {
        return new HealthSnapshot(
            id: $id,
            overallStatus: $status,
            results: [['name' => 'test', 'status' => $status->value, 'message' => 'OK', 'latency_ms' => $duration]],
            totalDurationMs: $duration,
            capturedAt: new DateTimeImmutable($time),
        );
    }

    private function createIncident(
        string $id,
        string $checkName,
        IncidentSeverity $severity,
        string $startedAt = '2026-03-27T10:00:00Z',
    ): Incident {
        return new Incident(
            id: $id,
            checkName: $checkName,
            severity: $severity,
            status: IncidentStatus::Open,
            message: "{$checkName} check failed",
            startedAt: new DateTimeImmutable($startedAt),
        );
    }

    private function createTables(): void
    {
        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS health_check_history (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                overall_status VARCHAR(20) NOT NULL,
                results_json TEXT NOT NULL,
                total_duration_ms REAL NOT NULL,
                captured_at TEXT NOT NULL
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_health_history_captured_at ON health_check_history (captured_at)
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS health_incidents (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                check_name VARCHAR(255) NOT NULL,
                severity VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                started_at TEXT NOT NULL,
                acknowledged_at TEXT NULL,
                resolved_at TEXT NULL
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_health_incidents_status ON health_incidents (status)
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_health_incidents_started_at ON health_incidents (started_at)
            SQL);
    }
}
