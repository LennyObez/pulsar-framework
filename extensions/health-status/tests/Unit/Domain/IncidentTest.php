<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;

#[CoversClass(Incident::class)]
final class IncidentTest extends TestCase
{
    #[Test]
    public function constructionWithRequiredFields(): void
    {
        $started = new DateTimeImmutable('2026-03-27T10:00:00Z');

        $incident = new Incident(
            id: 'inc-001',
            checkName: 'database',
            severity: IncidentSeverity::Major,
            status: IncidentStatus::Open,
            message: 'Connection refused',
            startedAt: $started,
        );

        self::assertSame('inc-001', $incident->id);
        self::assertSame('database', $incident->checkName);
        self::assertSame(IncidentSeverity::Major, $incident->severity);
        self::assertSame(IncidentStatus::Open, $incident->status);
        self::assertSame('Connection refused', $incident->message);
        self::assertSame($started, $incident->startedAt);
        self::assertNull($incident->acknowledgedAt);
        self::assertNull($incident->resolvedAt);
    }

    #[Test]
    public function acknowledgeReturnsNewInstanceWithTimestamp(): void
    {
        $incident = new Incident(
            id: 'inc-002',
            checkName: 'cache',
            severity: IncidentSeverity::Minor,
            status: IncidentStatus::Open,
            message: 'Cache miss rate high',
            startedAt: new DateTimeImmutable('2026-03-27T10:00:00Z'),
        );

        $ackTime = new DateTimeImmutable('2026-03-27T10:05:00Z');
        $acknowledged = $incident->acknowledge($ackTime);

        // Original is unchanged
        self::assertSame(IncidentStatus::Open, $incident->status);
        self::assertNull($incident->acknowledgedAt);

        // New instance has updated fields
        self::assertSame(IncidentStatus::Acknowledged, $acknowledged->status);
        self::assertSame($ackTime, $acknowledged->acknowledgedAt);
        self::assertSame('inc-002', $acknowledged->id);
        self::assertSame('cache', $acknowledged->checkName);
        self::assertNull($acknowledged->resolvedAt);
    }

    #[Test]
    public function resolveReturnsNewInstanceWithTimestamp(): void
    {
        $incident = new Incident(
            id: 'inc-003',
            checkName: 'disk',
            severity: IncidentSeverity::Critical,
            status: IncidentStatus::Open,
            message: 'Disk full',
            startedAt: new DateTimeImmutable('2026-03-27T10:00:00Z'),
        );

        $resolveTime = new DateTimeImmutable('2026-03-27T11:00:00Z');
        $resolved = $incident->resolve($resolveTime);

        // Original is unchanged
        self::assertSame(IncidentStatus::Open, $incident->status);
        self::assertNull($incident->resolvedAt);

        // New instance has updated fields
        self::assertSame(IncidentStatus::Resolved, $resolved->status);
        self::assertSame($resolveTime, $resolved->resolvedAt);
        self::assertSame('inc-003', $resolved->id);
    }

    #[Test]
    public function toArrayProducesExpectedStructure(): void
    {
        $started = new DateTimeImmutable('2026-03-27T10:00:00+00:00');
        $acked = new DateTimeImmutable('2026-03-27T10:05:00+00:00');

        $incident = new Incident(
            id: 'inc-004',
            checkName: 'database',
            severity: IncidentSeverity::Major,
            status: IncidentStatus::Acknowledged,
            message: 'Timeout',
            startedAt: $started,
            acknowledgedAt: $acked,
        );

        $array = $incident->toArray();

        self::assertSame('inc-004', $array['id']);
        self::assertSame('database', $array['check_name']);
        self::assertSame('major', $array['severity']);
        self::assertSame('acknowledged', $array['status']);
        self::assertSame('Timeout', $array['message']);
        self::assertSame('2026-03-27T10:00:00+00:00', $array['started_at']);
        self::assertSame('2026-03-27T10:05:00+00:00', $array['acknowledged_at']);
        self::assertNull($array['resolved_at']);
    }

    #[Test]
    public function fromArrayRoundtripsCorrectly(): void
    {
        $started = new DateTimeImmutable('2026-03-27T10:00:00+00:00');
        $resolved = new DateTimeImmutable('2026-03-27T12:00:00+00:00');

        $original = new Incident(
            id: 'inc-005',
            checkName: 'disk',
            severity: IncidentSeverity::Critical,
            status: IncidentStatus::Resolved,
            message: 'Disk full',
            startedAt: $started,
            resolvedAt: $resolved,
        );

        $restored = Incident::fromArray($original->toArray());

        self::assertSame($original->id, $restored->id);
        self::assertSame($original->checkName, $restored->checkName);
        self::assertSame($original->severity, $restored->severity);
        self::assertSame($original->status, $restored->status);
        self::assertSame($original->message, $restored->message);
        self::assertEquals($original->startedAt, $restored->startedAt);
        self::assertNull($restored->acknowledgedAt);
        self::assertEquals($original->resolvedAt, $restored->resolvedAt);
    }

    #[Test]
    public function resolveAfterAcknowledgePreservesAcknowledgedAt(): void
    {
        $incident = new Incident(
            id: 'inc-006',
            checkName: 'cache',
            severity: IncidentSeverity::Minor,
            status: IncidentStatus::Open,
            message: 'Slow',
            startedAt: new DateTimeImmutable('2026-03-27T10:00:00Z'),
        );

        $ackTime = new DateTimeImmutable('2026-03-27T10:05:00Z');
        $resolveTime = new DateTimeImmutable('2026-03-27T10:30:00Z');

        $acknowledged = $incident->acknowledge($ackTime);
        $resolved = $acknowledged->resolve($resolveTime);

        self::assertSame(IncidentStatus::Resolved, $resolved->status);
        self::assertSame($ackTime, $resolved->acknowledgedAt);
        self::assertSame($resolveTime, $resolved->resolvedAt);
    }
}
