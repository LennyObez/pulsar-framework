<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\Incident;
use Pulsar\Security\Incident\IncidentSeverity;

use function strlen;

#[CoversClass(Incident::class)]
final class IncidentTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsViaAccessors(): void
    {
        $reportedAt = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $incident = new Incident(
            id: 'inc-001',
            severity: IncidentSeverity::High,
            title: 'Brute-force detected',
            description: 'Multiple failed login attempts from 10.0.0.1',
            reportedAt: $reportedAt,
            source: 'auth-monitor',
            metadata: ['ip' => '10.0.0.1', 'attempts' => 42],
        );

        self::assertSame('inc-001', $incident->id());
        self::assertSame(IncidentSeverity::High, $incident->severity());
        self::assertSame('Brute-force detected', $incident->title());
        self::assertSame('Multiple failed login attempts from 10.0.0.1', $incident->description());
        self::assertSame($reportedAt, $incident->reportedAt());
        self::assertSame('auth-monitor', $incident->source());
        self::assertSame(['ip' => '10.0.0.1', 'attempts' => 42], $incident->metadata());
    }

    #[Test]
    public function metadataDefaultsToEmptyArray(): void
    {
        $incident = new Incident(
            id: 'inc-002',
            severity: IncidentSeverity::Low,
            title: 'Info',
            description: 'Informational',
            reportedAt: new DateTimeImmutable(),
            source: 'test',
        );

        self::assertSame([], $incident->metadata());
    }

    #[Test]
    public function createGeneratesUniqueIdAndTimestamp(): void
    {
        $before = new DateTimeImmutable();
        $incident = Incident::create(
            severity: IncidentSeverity::Critical,
            title: 'Data breach',
            description: 'Unauthorized data exfiltration detected',
            source: 'dlp-scanner',
            metadata: ['table' => 'users'],
        );
        $after = new DateTimeImmutable();

        self::assertSame(32, strlen($incident->id()));
        self::assertSame(IncidentSeverity::Critical, $incident->severity());
        self::assertSame('Data breach', $incident->title());
        self::assertSame('dlp-scanner', $incident->source());
        self::assertSame(['table' => 'users'], $incident->metadata());
        self::assertGreaterThanOrEqual($before, $incident->reportedAt());
        self::assertLessThanOrEqual($after, $incident->reportedAt());
    }

    #[Test]
    public function createGeneratesDistinctIds(): void
    {
        $a = Incident::create(IncidentSeverity::Low, 'A', 'desc a');
        $b = Incident::create(IncidentSeverity::Low, 'B', 'desc b');

        self::assertNotSame($a->id(), $b->id());
    }

    #[Test]
    public function createDefaultsSourceAndMetadata(): void
    {
        $incident = Incident::create(
            severity: IncidentSeverity::Medium,
            title: 'Scan warning',
            description: 'Port scan detected',
        );

        self::assertSame('', $incident->source());
        self::assertSame([], $incident->metadata());
    }

    #[Test]
    public function toArraySerializesAllFields(): void
    {
        $reportedAt = new DateTimeImmutable('2026-01-15T08:30:00+00:00');
        $incident = new Incident(
            id: 'inc-round-trip',
            severity: IncidentSeverity::High,
            title: 'Privilege escalation',
            description: 'User gained admin without authorization',
            reportedAt: $reportedAt,
            source: 'rbac-engine',
            metadata: ['userId' => 'u-99'],
        );

        $array = $incident->toArray();

        self::assertSame('inc-round-trip', $array['id']);
        self::assertSame('high', $array['severity']);
        self::assertSame('Privilege escalation', $array['title']);
        self::assertSame('User gained admin without authorization', $array['description']);
        self::assertSame('2026-01-15T08:30:00+00:00', $array['reported_at']);
        self::assertSame('rbac-engine', $array['source']);
        self::assertSame(['userId' => 'u-99'], $array['metadata']);
    }

    #[Test]
    public function fromArrayReconstructsIncident(): void
    {
        $data = [
            'id' => 'inc-from',
            'severity' => 'critical',
            'title' => 'SQL injection attempt',
            'description' => 'Malicious payload in query parameter',
            'reported_at' => '2026-03-15T12:00:00+00:00',
            'source' => 'waf',
            'metadata' => ['param' => 'q'],
        ];

        $incident = Incident::fromArray($data);

        self::assertSame('inc-from', $incident->id());
        self::assertSame(IncidentSeverity::Critical, $incident->severity());
        self::assertSame('SQL injection attempt', $incident->title());
        self::assertSame('Malicious payload in query parameter', $incident->description());
        self::assertSame('2026-03-15T12:00:00+00:00', $incident->reportedAt()->format(DateTimeImmutable::ATOM));
        self::assertSame('waf', $incident->source());
        self::assertSame(['param' => 'q'], $incident->metadata());
    }

    #[Test]
    public function roundTripPreservesAllData(): void
    {
        $original = Incident::create(
            severity: IncidentSeverity::Medium,
            title: 'Unusual traffic',
            description: 'Traffic spike from single region',
            source: 'geo-monitor',
            metadata: ['region' => 'eu-west-1'],
        );

        $restored = Incident::fromArray($original->toArray());

        self::assertSame($original->id(), $restored->id());
        self::assertSame($original->severity(), $restored->severity());
        self::assertSame($original->title(), $restored->title());
        self::assertSame($original->description(), $restored->description());
        self::assertSame(
            $original->reportedAt()->format(DateTimeImmutable::ATOM),
            $restored->reportedAt()->format(DateTimeImmutable::ATOM),
        );
        self::assertSame($original->source(), $restored->source());
        self::assertSame($original->metadata(), $restored->metadata());
    }

    #[Test]
    public function fromArrayDefaultsMetadataWhenMissing(): void
    {
        $data = [
            'id' => 'inc-no-meta',
            'severity' => 'low',
            'title' => 'Info event',
            'description' => 'Nothing critical',
            'reported_at' => '2026-03-15T00:00:00+00:00',
            'source' => 'test',
        ];

        $incident = Incident::fromArray($data);

        self::assertSame([], $incident->metadata());
    }

    // ── IncidentSeverity enum ─────────────────────────────────────

    #[Test]
    #[DataProvider('severityProvider')]
    public function severityBackedValues(IncidentSeverity $severity, string $expectedValue): void
    {
        self::assertSame($expectedValue, $severity->value);
    }

    /**
     * @return iterable<string, array{IncidentSeverity, string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'Low' => [IncidentSeverity::Low, 'low'];
        yield 'Medium' => [IncidentSeverity::Medium, 'medium'];
        yield 'High' => [IncidentSeverity::High, 'high'];
        yield 'Critical' => [IncidentSeverity::Critical, 'critical'];
    }

    #[Test]
    public function severityHasFourCases(): void
    {
        self::assertCount(4, IncidentSeverity::cases());
    }

    #[Test]
    public function severityFromBackedValue(): void
    {
        self::assertSame(IncidentSeverity::Low, IncidentSeverity::from('low'));
        self::assertSame(IncidentSeverity::Critical, IncidentSeverity::from('critical'));
    }
}
