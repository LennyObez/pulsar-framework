<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\Incident;
use Pulsar\Security\Incident\IncidentSeverity;

use function strlen;

#[CoversClass(Incident::class)]
final class IncidentExtendedTest extends TestCase
{
    public function testCreateFactory(): void
    {
        $incident = Incident::create(
            severity: IncidentSeverity::High,
            title: 'Brute force detected',
            description: '100 failed logins from 10.0.0.1',
            source: 'threat_detection',
            metadata: ['ip' => '10.0.0.1', 'count' => 100],
        );

        self::assertNotEmpty($incident->id());
        self::assertSame(32, strlen($incident->id())); // 16 bytes = 32 hex chars
        self::assertSame(IncidentSeverity::High, $incident->severity());
        self::assertSame('Brute force detected', $incident->title());
        self::assertSame('100 failed logins from 10.0.0.1', $incident->description());
        self::assertSame('threat_detection', $incident->source());
        self::assertSame(['ip' => '10.0.0.1', 'count' => 100], $incident->metadata());
        self::assertInstanceOf(DateTimeImmutable::class, $incident->reportedAt());
    }

    public function testCreateFactoryWithDefaults(): void
    {
        $incident = Incident::create(
            severity: IncidentSeverity::Low,
            title: 'Minor issue',
            description: 'A minor issue occurred',
        );

        self::assertSame('', $incident->source());
        self::assertSame([], $incident->metadata());
    }

    public function testToArray(): void
    {
        $reportedAt = new DateTimeImmutable('2026-03-15T12:00:00+00:00');

        $incident = new Incident(
            id: 'abc123',
            severity: IncidentSeverity::Critical,
            title: 'Data breach',
            description: 'Customer data exposed',
            reportedAt: $reportedAt,
            source: 'dlp_engine',
            metadata: ['affected_records' => 1000],
        );

        $array = $incident->toArray();

        self::assertSame('abc123', $array['id']);
        self::assertSame('critical', $array['severity']);
        self::assertSame('Data breach', $array['title']);
        self::assertSame('Customer data exposed', $array['description']);
        self::assertSame('2026-03-15T12:00:00+00:00', $array['reported_at']);
        self::assertSame('dlp_engine', $array['source']);
        self::assertSame(['affected_records' => 1000], $array['metadata']);
    }

    public function testFromArray(): void
    {
        $data = [
            'id' => 'inc-001',
            'severity' => 'high',
            'title' => 'Session hijack',
            'description' => 'IP changed mid-session',
            'reported_at' => '2026-03-15T10:30:00+00:00',
            'source' => 'session_monitor',
            'metadata' => ['old_ip' => '1.2.3.4', 'new_ip' => '5.6.7.8'],
        ];

        $incident = Incident::fromArray($data);

        self::assertSame('inc-001', $incident->id());
        self::assertSame(IncidentSeverity::High, $incident->severity());
        self::assertSame('Session hijack', $incident->title());
        self::assertSame('IP changed mid-session', $incident->description());
        self::assertSame('session_monitor', $incident->source());
        self::assertSame(['old_ip' => '1.2.3.4', 'new_ip' => '5.6.7.8'], $incident->metadata());
    }

    public function testFromArrayWithoutMetadata(): void
    {
        $data = [
            'id' => 'inc-002',
            'severity' => 'low',
            'title' => 'Minor alert',
            'description' => 'Nothing major',
            'reported_at' => '2026-03-15T11:00:00+00:00',
            'source' => 'monitor',
        ];

        $incident = Incident::fromArray($data);

        self::assertSame([], $incident->metadata());
    }

    public function testRoundTripSerialization(): void
    {
        $original = Incident::create(
            severity: IncidentSeverity::Medium,
            title: 'API abuse',
            description: 'High request volume detected',
            source: 'api_monitor',
            metadata: ['rate' => 500],
        );

        $array = $original->toArray();
        $restored = Incident::fromArray($array);

        self::assertSame($original->id(), $restored->id());
        self::assertSame($original->severity(), $restored->severity());
        self::assertSame($original->title(), $restored->title());
        self::assertSame($original->description(), $restored->description());
        self::assertSame($original->source(), $restored->source());
        self::assertSame($original->metadata(), $restored->metadata());
    }
}
