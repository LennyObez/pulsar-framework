<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Internal\Storage;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;
use Pulsar\Resilience\HealthCheck\HealthStatus;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed health history store using parameterized queries.
 */
#[Internal(reason: 'Use HealthHistoryStoreInterface for type declarations')]
final readonly class DatabaseHealthHistoryStore implements HealthHistoryStoreInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function storeSnapshot(HealthSnapshot $snapshot): void
    {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO health_check_history (id, overall_status, results_json, total_duration_ms, captured_at)
                VALUES (:id, :overall_status, :results_json, :total_duration_ms, :captured_at)
                SQL,
            [
                ':id' => $snapshot->id,
                ':overall_status' => $snapshot->overallStatus->value,
                ':results_json' => json_encode($snapshot->results, JSON_THROW_ON_ERROR),
                ':total_duration_ms' => $snapshot->totalDurationMs,
                ':captured_at' => $snapshot->capturedAt->format(DateTimeImmutable::ATOM),
            ],
        );
    }

    #[Override]
    public function recentSnapshots(int $limit = 50): array
    {
        $result = $this->connection->query(
            'SELECT * FROM health_check_history ORDER BY captured_at DESC LIMIT :limit',
            [':limit' => $limit],
        );

        return $result->map($this->hydrateSnapshot(...));
    }

    #[Override]
    public function snapshotsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $result = $this->connection->query(
            'SELECT * FROM health_check_history WHERE captured_at >= :from AND captured_at <= :to ORDER BY captured_at DESC',
            [
                ':from' => $from->format(DateTimeImmutable::ATOM),
                ':to' => $to->format(DateTimeImmutable::ATOM),
            ],
        );

        return $result->map($this->hydrateSnapshot(...));
    }

    #[Override]
    public function storeIncident(Incident $incident): void
    {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO health_incidents (id, check_name, severity, status, message, started_at, acknowledged_at, resolved_at)
                VALUES (:id, :check_name, :severity, :status, :message, :started_at, :acknowledged_at, :resolved_at)
                SQL,
            [
                ':id' => $incident->id,
                ':check_name' => $incident->checkName,
                ':severity' => $incident->severity->value,
                ':status' => $incident->status->value,
                ':message' => $incident->message,
                ':started_at' => $incident->startedAt->format(DateTimeImmutable::ATOM),
                ':acknowledged_at' => $incident->acknowledgedAt?->format(DateTimeImmutable::ATOM),
                ':resolved_at' => $incident->resolvedAt?->format(DateTimeImmutable::ATOM),
            ],
        );
    }

    #[Override]
    public function updateIncident(Incident $incident): void
    {
        $this->connection->execute(
            <<<'SQL'
                UPDATE health_incidents
                SET severity = :severity, status = :status, message = :message,
                    acknowledged_at = :acknowledged_at, resolved_at = :resolved_at
                WHERE id = :id
                SQL,
            [
                ':id' => $incident->id,
                ':severity' => $incident->severity->value,
                ':status' => $incident->status->value,
                ':message' => $incident->message,
                ':acknowledged_at' => $incident->acknowledgedAt?->format(DateTimeImmutable::ATOM),
                ':resolved_at' => $incident->resolvedAt?->format(DateTimeImmutable::ATOM),
            ],
        );
    }

    #[Override]
    public function activeIncidents(): array
    {
        $result = $this->connection->query(
            'SELECT * FROM health_incidents WHERE status IN (:open, :acked) ORDER BY started_at DESC',
            [
                ':open' => IncidentStatus::Open->value,
                ':acked' => IncidentStatus::Acknowledged->value,
            ],
        );

        return $result->map($this->hydrateIncident(...));
    }

    #[Override]
    public function recentIncidents(int $limit = 20): array
    {
        $result = $this->connection->query(
            'SELECT * FROM health_incidents ORDER BY started_at DESC LIMIT :limit',
            [':limit' => $limit],
        );

        return $result->map($this->hydrateIncident(...));
    }

    #[Override]
    public function deleteSnapshotsOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->connection->execute(
            'DELETE FROM health_check_history WHERE captured_at < :cutoff',
            [':cutoff' => $cutoff->format(DateTimeImmutable::ATOM)],
        );
    }

    #[Override]
    public function snapshotCount(): int
    {
        $result = $this->connection->query('SELECT COUNT(*) AS cnt FROM health_check_history');

        return $result->first()?->getInt('cnt') ?? 0;
    }

    private function hydrateSnapshot(Row $row): HealthSnapshot
    {
        /** @var list<array{name: string, status: string, message: string, latency_ms: float}> $results */
        $results = json_decode($row->getString('results_json'), true, 512, JSON_THROW_ON_ERROR);

        return new HealthSnapshot(
            id: $row->getString('id'),
            overallStatus: HealthStatus::from($row->getString('overall_status')),
            results: $results,
            totalDurationMs: $row->getFloat('total_duration_ms'),
            capturedAt: new DateTimeImmutable($row->getString('captured_at')),
        );
    }

    private function hydrateIncident(Row $row): Incident
    {
        $acknowledgedAt = $row->getNullableString('acknowledged_at');
        $resolvedAt = $row->getNullableString('resolved_at');

        return new Incident(
            id: $row->getString('id'),
            checkName: $row->getString('check_name'),
            severity: IncidentSeverity::from($row->getString('severity')),
            status: IncidentStatus::from($row->getString('status')),
            message: $row->getString('message'),
            startedAt: new DateTimeImmutable($row->getString('started_at')),
            acknowledgedAt: $acknowledgedAt !== null ? new DateTimeImmutable($acknowledgedAt) : null,
            resolvedAt: $resolvedAt !== null ? new DateTimeImmutable($resolvedAt) : null,
        );
    }
}
