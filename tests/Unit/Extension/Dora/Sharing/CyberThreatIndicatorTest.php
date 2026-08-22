<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Dora\Sharing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Sharing\CyberThreatIndicator;
use Pulsar\Extension\Dora\Sharing\ThreatSeverity;

#[CoversClass(CyberThreatIndicator::class)]
final class CyberThreatIndicatorTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $indicator = new CyberThreatIndicator(
            id: 'CTI-001',
            type: 'ip_address',
            value: '192.168.1.100',
            source: 'Internal SIEM',
            severity: ThreatSeverity::High,
            observedAt: new DateTimeImmutable('2025-03-14T08:00:00+00:00'),
        );

        self::assertSame('CTI-001', $indicator->id);
        self::assertSame(ThreatSeverity::High, $indicator->severity);
        self::assertNull($indicator->description);
        self::assertSame([], $indicator->affectedSectors);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $indicator = new CyberThreatIndicator(
            id: 'CTI-001',
            type: 'hash',
            value: 'abc123def456',
            source: 'Threat Feed',
            severity: ThreatSeverity::Medium,
            observedAt: new DateTimeImmutable('2025-03-14T08:00:00+00:00'),
        );

        $data = $indicator->toArray();

        self::assertSame('medium', $data['severity']);
        self::assertArrayNotHasKey('description', $data);
        self::assertArrayNotHasKey('mitigation', $data);
        self::assertArrayNotHasKey('affected_sectors', $data);
        self::assertArrayNotHasKey('expires_at', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $indicator = new CyberThreatIndicator(
            id: 'CTI-002',
            type: 'domain',
            value: 'malicious-banking.example.com',
            source: 'FS-ISAC',
            severity: ThreatSeverity::Critical,
            observedAt: new DateTimeImmutable('2025-03-14T08:00:00+00:00'),
            description: 'Phishing domain targeting bank customers',
            mitigation: 'Block at DNS and proxy level',
            affectedSectors: ['Banking', 'Insurance'],
            expiresAt: new DateTimeImmutable('2025-04-14T08:00:00+00:00'),
        );

        /** @var array<string, mixed> $data */
        $data = $indicator->toArray();

        self::assertSame('critical', $data['severity']);
        self::assertSame('Phishing domain targeting bank customers', $data['description']);
        self::assertSame('Block at DNS and proxy level', $data['mitigation']);
        /** @var list<string> $sectors */
        $sectors = $data['affected_sectors'];
        self::assertCount(2, $sectors);
        self::assertArrayHasKey('expires_at', $data);
    }

    #[Test]
    public function threatSeverityEnumHasAllLevels(): void
    {
        self::assertCount(5, ThreatSeverity::cases());
        self::assertSame('critical', ThreatSeverity::Critical->value);
        self::assertSame('informational', ThreatSeverity::Informational->value);
    }
}
