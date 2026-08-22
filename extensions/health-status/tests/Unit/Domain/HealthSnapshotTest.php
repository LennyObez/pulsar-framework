<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Resilience\HealthCheck\HealthStatus;

#[CoversClass(HealthSnapshot::class)]
final class HealthSnapshotTest extends TestCase
{
    #[Test]
    public function constructionWithValidData(): void
    {
        $now = new DateTimeImmutable('2026-03-27T12:00:00Z');
        $results = [
            ['name' => 'database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 1.5],
            ['name' => 'cache', 'status' => 'degraded', 'message' => 'Slow', 'latency_ms' => 150.0],
        ];

        $snapshot = new HealthSnapshot(
            id: 'snap-001',
            overallStatus: HealthStatus::Degraded,
            results: $results,
            totalDurationMs: 151.5,
            capturedAt: $now,
        );

        self::assertSame('snap-001', $snapshot->id);
        self::assertSame(HealthStatus::Degraded, $snapshot->overallStatus);
        self::assertCount(2, $snapshot->results);
        self::assertSame(151.5, $snapshot->totalDurationMs);
        self::assertSame($now, $snapshot->capturedAt);
    }

    #[Test]
    public function toArrayProducesExpectedStructure(): void
    {
        $now = new DateTimeImmutable('2026-03-27T12:00:00+00:00');
        $results = [
            ['name' => 'database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 1.5],
        ];

        $snapshot = new HealthSnapshot(
            id: 'snap-002',
            overallStatus: HealthStatus::Healthy,
            results: $results,
            totalDurationMs: 1.5,
            capturedAt: $now,
        );

        $array = $snapshot->toArray();

        self::assertSame('snap-002', $array['id']);
        self::assertSame('healthy', $array['overall_status']);
        self::assertSame($results, $array['results']);
        self::assertSame(1.5, $array['total_duration_ms']);
        self::assertSame('2026-03-27T12:00:00+00:00', $array['captured_at']);
    }

    #[Test]
    public function fromArrayRoundtripsCorrectly(): void
    {
        $now = new DateTimeImmutable('2026-03-27T12:00:00+00:00');
        $results = [
            ['name' => 'database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 1.5],
            ['name' => 'disk', 'status' => 'unhealthy', 'message' => 'Full', 'latency_ms' => 0.5],
        ];

        $original = new HealthSnapshot(
            id: 'snap-003',
            overallStatus: HealthStatus::Unhealthy,
            results: $results,
            totalDurationMs: 2.0,
            capturedAt: $now,
        );

        $restored = HealthSnapshot::fromArray($original->toArray());

        self::assertSame($original->id, $restored->id);
        self::assertSame($original->overallStatus, $restored->overallStatus);
        self::assertSame($original->results, $restored->results);
        self::assertSame($original->totalDurationMs, $restored->totalDurationMs);
        self::assertEquals($original->capturedAt, $restored->capturedAt);
    }

    #[Test]
    public function fromArrayWithMinimalData(): void
    {
        $snapshot = HealthSnapshot::fromArray([
            'id' => 'snap-min',
            'overall_status' => 'healthy',
            'results' => [],
            'total_duration_ms' => 0.0,
            'captured_at' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame('snap-min', $snapshot->id);
        self::assertSame(HealthStatus::Healthy, $snapshot->overallStatus);
        self::assertSame([], $snapshot->results);
        self::assertSame(0.0, $snapshot->totalDurationMs);
    }

    #[Test]
    public function emptyResultsListIsValid(): void
    {
        $snapshot = new HealthSnapshot(
            id: 'snap-empty',
            overallStatus: HealthStatus::Healthy,
            results: [],
            totalDurationMs: 0.0,
            capturedAt: new DateTimeImmutable(),
        );

        self::assertSame([], $snapshot->results);
        $array = $snapshot->toArray();
        self::assertSame([], $array['results']);
    }
}
