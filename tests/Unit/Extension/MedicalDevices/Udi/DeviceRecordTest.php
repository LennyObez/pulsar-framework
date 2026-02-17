<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Udi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRecord;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRiskClass;
use Pulsar\Extension\MedicalDevices\Udi\DeviceStatus;
use Pulsar\Extension\MedicalDevices\Udi\UdiIdentifier;

#[CoversClass(DeviceRecord::class)]
final class DeviceRecordTest extends TestCase
{
    private function createUdi(): UdiIdentifier
    {
        return new UdiIdentifier(
            deviceIdentifier: '04012345678901',
            lotNumber: 'LOT-001',
        );
    }

    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $udi = $this->createUdi();
        $device = new DeviceRecord(
            udi: $udi,
            deviceName: 'Cardiac Monitor X100',
            manufacturer: 'MedTech Corp',
            riskClass: DeviceRiskClass::ClassIIb,
        );

        self::assertSame($udi, $device->udi);
        self::assertSame('Cardiac Monitor X100', $device->deviceName);
        self::assertSame('MedTech Corp', $device->manufacturer);
        self::assertSame(DeviceRiskClass::ClassIIb, $device->riskClass);
        self::assertSame(DeviceStatus::Active, $device->status);
        self::assertNull($device->notifiedBody);
    }

    #[Test]
    public function toArrayOmitsNullOptionalFields(): void
    {
        $device = new DeviceRecord(
            udi: $this->createUdi(),
            deviceName: 'Test Device',
            manufacturer: 'Test Mfg',
            riskClass: DeviceRiskClass::ClassI,
        );

        $data = $device->toArray();

        self::assertArrayHasKey('udi', $data);
        self::assertSame('Test Device', $data['device_name']);
        self::assertSame('I', $data['risk_class']);
        self::assertSame('active', $data['status']);
        self::assertArrayNotHasKey('notified_body', $data);
        self::assertArrayNotHasKey('certificate_number', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $device = new DeviceRecord(
            udi: $this->createUdi(),
            deviceName: 'Hip Implant Z300',
            manufacturer: 'OrthoMed',
            riskClass: DeviceRiskClass::ClassIII,
            notifiedBody: 'NB-1234',
            intendedPurpose: 'Total hip replacement',
            status: DeviceStatus::Recalled,
            certificateNumber: 'CE-2024-0001',
            certificateExpiry: '2029-06-30',
        );

        $data = $device->toArray();

        self::assertSame('NB-1234', $data['notified_body']);
        self::assertSame('Total hip replacement', $data['intended_purpose']);
        self::assertSame('recalled', $data['status']);
        self::assertSame('CE-2024-0001', $data['certificate_number']);
        self::assertSame('2029-06-30', $data['certificate_expiry']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = new DeviceRecord(
            udi: $this->createUdi(),
            deviceName: 'Test Device',
            manufacturer: 'Test Mfg',
            riskClass: DeviceRiskClass::ClassIIa,
            notifiedBody: 'NB-5678',
            status: DeviceStatus::Suspended,
        );

        $restored = DeviceRecord::fromArray($original->toArray());

        self::assertSame($original->deviceName, $restored->deviceName);
        self::assertSame($original->manufacturer, $restored->manufacturer);
        self::assertSame($original->riskClass, $restored->riskClass);
        self::assertSame($original->notifiedBody, $restored->notifiedBody);
        self::assertSame($original->status, $restored->status);
    }

    #[Test]
    public function fromArrayDefaultsStatusToActive(): void
    {
        $data = [
            'udi' => ['device_identifier' => 'DI-001'],
            'device_name' => 'Test',
            'manufacturer' => 'Mfg',
            'risk_class' => 'I',
        ];

        $device = DeviceRecord::fromArray($data);

        self::assertSame(DeviceStatus::Active, $device->status);
    }
}
