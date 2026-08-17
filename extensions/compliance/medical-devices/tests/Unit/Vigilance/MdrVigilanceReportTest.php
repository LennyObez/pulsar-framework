<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Vigilance;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Vigilance\MdrVigilanceReport;
use Pulsar\Extension\MedicalDevices\Vigilance\SeriousIncidentType;
use Pulsar\Extension\MedicalDevices\Vigilance\VigilanceReportStatus;

#[CoversClass(MdrVigilanceReport::class)]
final class MdrVigilanceReportTest extends TestCase
{
    #[Test]
    public function toArrayMinimal(): void
    {
        $report = new MdrVigilanceReport(
            id: 'vig-1',
            deviceIdentifier: 'UDI-001',
            manufacturer: 'Acme Medical',
            incidentDate: new DateTimeImmutable('2026-03-10'),
            reportDate: new DateTimeImmutable('2026-03-12'),
            incidentType: SeriousIncidentType::Other,
            incidentDescription: 'Minor software glitch during self-test.',
        );

        $array = $report->toArray();

        self::assertSame('vig-1', $array['id']);
        self::assertSame('UDI-001', $array['device_identifier']);
        self::assertSame('Acme Medical', $array['manufacturer']);
        self::assertSame('2026-03-10', $array['incident_date']);
        self::assertSame('2026-03-12', $array['report_date']);
        self::assertSame('other', $array['incident_type']);
        self::assertSame('initial', $array['status']);
        self::assertFalse($array['is_serious']);
        self::assertFalse($array['is_trending']);
        self::assertArrayNotHasKey('device_lot_number', $array);
        self::assertArrayNotHasKey('device_serial_number', $array);
        self::assertArrayNotHasKey('patient_outcomes', $array);
        self::assertArrayNotHasKey('root_cause_assessment', $array);
        self::assertArrayNotHasKey('corrective_action_taken', $array);
        self::assertArrayNotHasKey('competent_authority_reference', $array);
    }

    #[Test]
    public function toArrayFull(): void
    {
        $report = new MdrVigilanceReport(
            id: 'vig-2',
            deviceIdentifier: 'UDI-002',
            manufacturer: 'MedCorp',
            incidentDate: new DateTimeImmutable('2026-03-01'),
            reportDate: new DateTimeImmutable('2026-03-02'),
            incidentType: SeriousIncidentType::SeriousDeteriorationOfHealth,
            incidentDescription: 'Device caused severe allergic reaction.',
            deviceLotNumber: 'LOT-2025-A',
            deviceSerialNumber: 'SN-12345',
            patientOutcomes: ['Hospitalization', 'Full recovery after treatment'],
            rootCauseAssessment: 'Material defect in biocompatible coating.',
            correctiveActionTaken: 'Recalled affected lot, updated coating process.',
            status: VigilanceReportStatus::Final,
            competentAuthorityReference: 'CA-REF-2026-001',
            isTrending: true,
        );

        $array = $report->toArray();

        self::assertSame('serious_deterioration_of_health', $array['incident_type']);
        self::assertSame('final', $array['status']);
        self::assertTrue($array['is_serious']);
        self::assertTrue($array['is_trending']);
        self::assertSame('LOT-2025-A', $array['device_lot_number']);
        self::assertSame('SN-12345', $array['device_serial_number']);
        self::assertCount(2, $array['patient_outcomes']);
        self::assertSame('Material defect in biocompatible coating.', $array['root_cause_assessment']);
        self::assertSame('CA-REF-2026-001', $array['competent_authority_reference']);
    }

    /**
     * @return iterable<string, array{SeriousIncidentType, bool}>
     */
    public static function seriousIncidentProvider(): iterable
    {
        yield 'Death is serious' => [SeriousIncidentType::Death, true];
        yield 'Serious deterioration is serious' => [SeriousIncidentType::SeriousDeteriorationOfHealth, true];
        yield 'Public health threat is serious' => [SeriousIncidentType::PublicHealthThreat, true];
        yield 'Other is not serious' => [SeriousIncidentType::Other, false];
    }

    #[Test]
    #[DataProvider('seriousIncidentProvider')]
    public function isSeriousIncidentClassifiesCorrectly(SeriousIncidentType $type, bool $expected): void
    {
        $report = new MdrVigilanceReport(
            id: 'vig-test',
            deviceIdentifier: 'UDI-X',
            manufacturer: 'Test',
            incidentDate: new DateTimeImmutable('2026-01-01'),
            reportDate: new DateTimeImmutable('2026-01-02'),
            incidentType: $type,
            incidentDescription: 'Test incident',
        );

        self::assertSame($expected, $report->isSeriousIncident());
    }

    #[Test]
    public function seriousIncidentTypeEnumCases(): void
    {
        $cases = SeriousIncidentType::cases();

        self::assertCount(4, $cases);
        self::assertSame('death', SeriousIncidentType::Death->value);
        self::assertSame('serious_deterioration_of_health', SeriousIncidentType::SeriousDeteriorationOfHealth->value);
        self::assertSame('public_health_threat', SeriousIncidentType::PublicHealthThreat->value);
        self::assertSame('other', SeriousIncidentType::Other->value);
    }

    #[Test]
    public function vigilanceReportStatusEnumCases(): void
    {
        $cases = VigilanceReportStatus::cases();

        self::assertCount(4, $cases);
        self::assertSame('initial', VigilanceReportStatus::Initial->value);
        self::assertSame('follow_up', VigilanceReportStatus::FollowUp->value);
        self::assertSame('final', VigilanceReportStatus::Final->value);
        self::assertSame('closed', VigilanceReportStatus::Closed->value);
    }
}
