<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Udi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRiskClass;
use Pulsar\Extension\MedicalDevices\Udi\DeviceStatus;
use Pulsar\Extension\MedicalDevices\Udi\UdiIdentifier;
use Pulsar\Extension\MedicalDevices\Udi\UdiIssuingAgency;

#[CoversClass(UdiIdentifier::class)]
#[CoversClass(DeviceRiskClass::class)]
#[CoversClass(DeviceStatus::class)]
final class UdiIdentifierTest extends TestCase
{
    #[Test]
    public function minimalIdentifierUsesGs1Default(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: '00012345678905');

        self::assertSame('00012345678905', $udi->deviceIdentifier);
        self::assertSame(UdiIssuingAgency::GS1, $udi->issuingAgency);
        self::assertNull($udi->lotNumber);
        self::assertNull($udi->serialNumber);
        self::assertNull($udi->expirationDate);
        self::assertNull($udi->manufacturingDate);
        self::assertNull($udi->humanReadable);
    }

    #[Test]
    public function fullUdiWithOnlyDeviceIdentifier(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: 'DI-001');
        self::assertSame('DI-001', $udi->fullUdi());
    }

    #[Test]
    public function fullUdiWithAllProductionIdentifiers(): void
    {
        $udi = new UdiIdentifier(
            deviceIdentifier: 'DI-001',
            lotNumber: 'LOT-A',
            serialNumber: 'SN-123',
            expirationDate: '2027-12-31',
            manufacturingDate: '2026-01-15',
        );

        self::assertSame('DI-001|LOT:LOT-A|SN:SN-123|EXP:2027-12-31|MFG:2026-01-15', $udi->fullUdi());
    }

    #[Test]
    public function fullUdiWithPartialProductionIdentifiers(): void
    {
        $udi = new UdiIdentifier(
            deviceIdentifier: 'DI-002',
            lotNumber: 'BATCH-99',
            expirationDate: '2028-06-30',
        );

        self::assertSame('DI-002|LOT:BATCH-99|EXP:2028-06-30', $udi->fullUdi());
    }

    #[Test]
    public function toArrayWithMinimalIdentifier(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: 'DI-003');
        $array = $udi->toArray();

        self::assertSame('DI-003', $array['device_identifier']);
        self::assertSame('gs1', $array['issuing_agency']);
        self::assertArrayNotHasKey('lot_number', $array);
        self::assertArrayNotHasKey('serial_number', $array);
    }

    #[Test]
    public function toArrayWithFullIdentifier(): void
    {
        $udi = new UdiIdentifier(
            deviceIdentifier: 'DI-004',
            lotNumber: 'L1',
            serialNumber: 'S1',
            expirationDate: '2028-01-01',
            manufacturingDate: '2026-06-01',
            issuingAgency: UdiIssuingAgency::HIBCC,
            humanReadable: '(01)DI-004(10)L1',
        );

        $array = $udi->toArray();

        self::assertSame('L1', $array['lot_number']);
        self::assertSame('S1', $array['serial_number']);
        self::assertSame('2028-01-01', $array['expiration_date']);
        self::assertSame('2026-06-01', $array['manufacturing_date']);
        self::assertSame('hibcc', $array['issuing_agency']);
        self::assertSame('(01)DI-004(10)L1', $array['human_readable']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $data = [
            'device_identifier' => 'DI-005',
            'issuing_agency' => 'iccbba',
            'lot_number' => 'LOT-X',
            'serial_number' => 'SN-99',
        ];

        $udi = UdiIdentifier::fromArray($data);

        self::assertSame('DI-005', $udi->deviceIdentifier);
        self::assertSame(UdiIssuingAgency::ICCBBA, $udi->issuingAgency);
        self::assertSame('LOT-X', $udi->lotNumber);
        self::assertSame('SN-99', $udi->serialNumber);
    }

    /**
     * @return iterable<string, array{DeviceRiskClass, string}>
     */
    public static function riskClassProvider(): iterable
    {
        yield 'class_I' => [DeviceRiskClass::ClassI, 'I'];
        yield 'class_IIa' => [DeviceRiskClass::ClassIIa, 'IIa'];
        yield 'class_IIb' => [DeviceRiskClass::ClassIIb, 'IIb'];
        yield 'class_III' => [DeviceRiskClass::ClassIII, 'III'];
    }

    #[Test]
    #[DataProvider('riskClassProvider')]
    public function deviceRiskClassValues(DeviceRiskClass $class, string $expected): void
    {
        self::assertSame($expected, $class->value);
    }

    /**
     * @return iterable<string, array{DeviceStatus, string}>
     */
    public static function deviceStatusProvider(): iterable
    {
        yield 'active' => [DeviceStatus::Active, 'active'];
        yield 'recalled' => [DeviceStatus::Recalled, 'recalled'];
        yield 'suspended' => [DeviceStatus::Suspended, 'suspended'];
        yield 'withdrawn' => [DeviceStatus::Withdrawn, 'withdrawn'];
        yield 'expired' => [DeviceStatus::Expired, 'expired'];
    }

    #[Test]
    #[DataProvider('deviceStatusProvider')]
    public function deviceStatusValues(DeviceStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }
}
