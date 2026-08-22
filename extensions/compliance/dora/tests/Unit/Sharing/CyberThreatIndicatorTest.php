<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit\Sharing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Sharing\CyberThreatIndicator;
use Pulsar\Extension\Dora\Sharing\ThreatSeverity;

#[CoversClass(CyberThreatIndicator::class)]
final class CyberThreatIndicatorTest extends TestCase
{
    #[Test]
    public function minimalConstructorSetsDefaults(): void
    {
        $observed = new DateTimeImmutable('2026-01-20T08:00:00+00:00');
        $indicator = new CyberThreatIndicator(
            id: 'CTI-001',
            type: 'ip_address',
            value: '192.0.2.1',
            source: 'FS-ISAC',
            severity: ThreatSeverity::High,
            observedAt: $observed,
        );

        self::assertSame('CTI-001', $indicator->id);
        self::assertSame('ip_address', $indicator->type);
        self::assertSame('192.0.2.1', $indicator->value);
        self::assertSame(ThreatSeverity::High, $indicator->severity);
        self::assertNull($indicator->description);
        self::assertNull($indicator->mitigation);
        self::assertSame([], $indicator->affectedSectors);
        self::assertNull($indicator->expiresAt);
    }

    #[Test]
    public function toArrayWithMinimalIndicator(): void
    {
        $observed = new DateTimeImmutable('2026-01-20T08:00:00+00:00');
        $indicator = new CyberThreatIndicator(
            id: 'CTI-002',
            type: 'domain',
            value: 'malicious.example.com',
            source: 'CERT-EU',
            severity: ThreatSeverity::Critical,
            observedAt: $observed,
        );

        $array = $indicator->toArray();

        self::assertSame('CTI-002', $array['id']);
        self::assertSame('domain', $array['type']);
        self::assertSame('malicious.example.com', $array['value']);
        self::assertSame('CERT-EU', $array['source']);
        self::assertSame('critical', $array['severity']);
        self::assertSame('2026-01-20T08:00:00+00:00', $array['observed_at']);
        self::assertArrayNotHasKey('description', $array);
        self::assertArrayNotHasKey('mitigation', $array);
        self::assertArrayNotHasKey('affected_sectors', $array);
        self::assertArrayNotHasKey('expires_at', $array);
    }

    #[Test]
    public function toArrayWithFullIndicator(): void
    {
        $observed = new DateTimeImmutable('2026-01-20T08:00:00+00:00');
        $expires = new DateTimeImmutable('2026-02-20T08:00:00+00:00');

        $indicator = new CyberThreatIndicator(
            id: 'CTI-003',
            type: 'hash',
            value: 'abc123def456',
            source: 'Internal SOC',
            severity: ThreatSeverity::Medium,
            observedAt: $observed,
            description: 'Malware hash detected in phishing campaign',
            mitigation: 'Block hash at endpoint protection layer',
            affectedSectors: ['banking', 'insurance'],
            expiresAt: $expires,
        );

        $array = $indicator->toArray();

        self::assertSame('Malware hash detected in phishing campaign', $array['description']);
        self::assertSame('Block hash at endpoint protection layer', $array['mitigation']);
        self::assertSame(['banking', 'insurance'], $array['affected_sectors']);
        self::assertSame('2026-02-20T08:00:00+00:00', $array['expires_at']);
    }

    /**
     * @return iterable<string, array{ThreatSeverity, string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'critical' => [ThreatSeverity::Critical, 'critical'];
        yield 'high' => [ThreatSeverity::High, 'high'];
        yield 'medium' => [ThreatSeverity::Medium, 'medium'];
        yield 'low' => [ThreatSeverity::Low, 'low'];
        yield 'informational' => [ThreatSeverity::Informational, 'informational'];
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function severityEnumHasExpectedValue(ThreatSeverity $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }
}
