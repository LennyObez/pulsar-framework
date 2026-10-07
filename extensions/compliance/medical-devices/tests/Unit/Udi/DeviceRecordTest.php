<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Udi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRecord;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRiskClass;
use Pulsar\Extension\MedicalDevices\Udi\DeviceStatus;
use Pulsar\Extension\MedicalDevices\Udi\UdiIdentifier;
use Pulsar\Extension\MedicalDevices\Udi\UdiIssuingAgency;

#[CoversClass(DeviceRecord::class)]
final class DeviceRecordTest extends TestCase
{
    #[Test]
    public function minimalRecordDefaultsToActive(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: 'DI-001');
        $record = new DeviceRecord(
            udi: $udi,
            deviceName: 'Pulse Oximeter',
            manufacturer: 'MedCo',
            riskClass: DeviceRiskClass::ClassIIa,
        );

        self::assertSame(DeviceStatus::Active, $record->status);
        self::assertNull($record->notifiedBody);
        self::assertNull($record->intendedPurpose);
        self::assertNull($record->certificateNumber);
        self::assertNull($record->certificateExpiry);
    }

    #[Test]
    public function toArrayWithMinimalRecord(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: 'DI-002');
        $record = new DeviceRecord(
            udi: $udi,
            deviceName: 'Thermometer',
            manufacturer: 'TempCo',
            riskClass: DeviceRiskClass::ClassI,
        );

        $array = $record->toArray();

        self::assertSame('Thermometer', $array['device_name']);
        self::assertSame('TempCo', $array['manufacturer']);
        self::assertSame('I', $array['risk_class']);
        self::assertSame('active', $array['status']);
        self::assertArrayHasKey('udi', $array);
        self::assertArrayNotHasKey('notified_body', $array);
        self::assertArrayNotHasKey('certificate_number', $array);
    }

    #[Test]
    public function toArrayWithFullRecord(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: 'DI-003', lotNumber: 'LOT-1');
        $record = new DeviceRecord(
            udi: $udi,
            deviceName: 'Cardiac Monitor',
            manufacturer: 'HeartTech',
            riskClass: DeviceRiskClass::ClassIII,
            notifiedBody: 'BSI 0086',
            intendedPurpose: 'Continuous cardiac monitoring',
            status: DeviceStatus::Active,
            certificateNumber: 'CE-2026-001',
            certificateExpiry: '2031-12-31',
        );

        $array = $record->toArray();

        self::assertSame('BSI 0086', $array['notified_body']);
        self::assertSame('Continuous cardiac monitoring', $array['intended_purpose']);
        self::assertSame('CE-2026-001', $array['certificate_number']);
        self::assertSame('2031-12-31', $array['certificate_expiry']);
        self::assertSame('III', $array['risk_class']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $data = [
            'udi' => [
                'device_identifier' => 'DI-004',
                'issuing_agency' => 'hibcc',
            ],
            'device_name' => 'Blood Analyzer',
            'manufacturer' => 'LabCo',
            'risk_class' => 'IIb',
            'status' => 'suspended',
            'notified_body' => 'TUV 0123',
        ];

        $record = DeviceRecord::fromArray($data);

        self::assertSame('DI-004', $record->udi->deviceIdentifier);
        self::assertSame(UdiIssuingAgency::HIBCC, $record->udi->issuingAgency);
        self::assertSame('Blood Analyzer', $record->deviceName);
        self::assertSame('LabCo', $record->manufacturer);
        self::assertSame(DeviceRiskClass::ClassIIb, $record->riskClass);
        self::assertSame(DeviceStatus::Suspended, $record->status);
        self::assertSame('TUV 0123', $record->notifiedBody);
    }

    #[Test]
    public function fromArrayDefaultsStatusToActive(): void
    {
        $data = [
            'udi' => ['device_identifier' => 'DI-005'],
            'device_name' => 'Stethoscope',
            'manufacturer' => 'AudioMed',
            'risk_class' => 'I',
        ];

        $record = DeviceRecord::fromArray($data);

        self::assertSame(DeviceStatus::Active, $record->status);
    }
}
