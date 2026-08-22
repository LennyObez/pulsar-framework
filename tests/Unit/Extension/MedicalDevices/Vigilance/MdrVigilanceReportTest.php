<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Vigilance;

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
    private function createReport(SeriousIncidentType $type = SeriousIncidentType::Death): MdrVigilanceReport
    {
        return new MdrVigilanceReport(
            id: 'VIG-001',
            deviceIdentifier: 'DI-001',
            manufacturer: 'MedTech Corp',
            incidentDate: new DateTimeImmutable('2025-03-10'),
            reportDate: new DateTimeImmutable('2025-03-12'),
            incidentType: $type,
            incidentDescription: 'Device malfunction during procedure',
        );
    }

    #[Test]
    #[DataProvider('seriousIncidentProvider')]
    public function classifiesSeriousIncidents(SeriousIncidentType $type, bool $expected): void
    {
        $report = $this->createReport($type);

        self::assertSame($expected, $report->isSeriousIncident());
    }

    /** @return iterable<string, array{SeriousIncidentType, bool}> */
    public static function seriousIncidentProvider(): iterable
    {
        yield 'death' => [SeriousIncidentType::Death, true];
        yield 'serious deterioration' => [SeriousIncidentType::SeriousDeteriorationOfHealth, true];
        yield 'public health threat' => [SeriousIncidentType::PublicHealthThreat, true];
        yield 'other' => [SeriousIncidentType::Other, false];
    }

    #[Test]
    public function constructsWithDefaults(): void
    {
        $report = $this->createReport();

        self::assertSame(VigilanceReportStatus::Initial, $report->status);
        self::assertFalse($report->isTrending);
        self::assertSame([], $report->patientOutcomes);
        self::assertNull($report->rootCauseAssessment);
    }

    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $report = $this->createReport();
        $data = $report->toArray();

        self::assertSame('VIG-001', $data['id']);
        self::assertSame('DI-001', $data['device_identifier']);
        self::assertSame('2025-03-10', $data['incident_date']);
        self::assertSame('2025-03-12', $data['report_date']);
        self::assertSame('death', $data['incident_type']);
        self::assertSame('initial', $data['status']);
        self::assertTrue($data['is_serious']);
        self::assertFalse($data['is_trending']);
    }

    #[Test]
    public function toArrayOmitsNullOptionalFields(): void
    {
        $data = $this->createReport()->toArray();

        self::assertArrayNotHasKey('device_lot_number', $data);
        self::assertArrayNotHasKey('device_serial_number', $data);
        self::assertArrayNotHasKey('patient_outcomes', $data);
        self::assertArrayNotHasKey('root_cause_assessment', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $report = new MdrVigilanceReport(
            id: 'VIG-002',
            deviceIdentifier: 'DI-002',
            manufacturer: 'MedTech Corp',
            incidentDate: new DateTimeImmutable('2025-03-10'),
            reportDate: new DateTimeImmutable('2025-03-12'),
            incidentType: SeriousIncidentType::SeriousDeteriorationOfHealth,
            incidentDescription: 'Patient required emergency intervention',
            deviceLotNumber: 'LOT-A',
            deviceSerialNumber: 'SN-123',
            patientOutcomes: ['Full recovery'],
            rootCauseAssessment: 'Sensor failure',
            correctiveActionTaken: 'Recalled lot',
            status: VigilanceReportStatus::Final,
            competentAuthorityReference: 'CA-REF-2025-001',
            isTrending: true,
        );

        $data = $report->toArray();

        self::assertSame('LOT-A', $data['device_lot_number']);
        self::assertSame('SN-123', $data['device_serial_number']);
        self::assertIsArray($data['patient_outcomes']);
        self::assertCount(1, $data['patient_outcomes']);
        self::assertSame('Sensor failure', $data['root_cause_assessment']);
        self::assertSame('Recalled lot', $data['corrective_action_taken']);
        self::assertSame('final', $data['status']);
        self::assertSame('CA-REF-2025-001', $data['competent_authority_reference']);
        self::assertTrue($data['is_trending']);
    }
}
