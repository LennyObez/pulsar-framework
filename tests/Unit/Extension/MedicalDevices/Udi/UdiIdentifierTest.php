<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Udi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Udi\UdiIdentifier;
use Pulsar\Extension\MedicalDevices\Udi\UdiIssuingAgency;

#[CoversClass(UdiIdentifier::class)]
final class UdiIdentifierTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFieldsOnly(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: '04012345678901');

        self::assertSame('04012345678901', $udi->deviceIdentifier);
        self::assertNull($udi->lotNumber);
        self::assertNull($udi->serialNumber);
        self::assertNull($udi->expirationDate);
        self::assertNull($udi->manufacturingDate);
        self::assertSame(UdiIssuingAgency::GS1, $udi->issuingAgency);
        self::assertNull($udi->humanReadable);
    }

    #[Test]
    public function constructsWithAllFields(): void
    {
        $udi = new UdiIdentifier(
            deviceIdentifier: '04012345678901',
            lotNumber: 'LOT-2024-001',
            serialNumber: 'SN-12345',
            expirationDate: '2026-12-31',
            manufacturingDate: '2024-01-15',
            issuingAgency: UdiIssuingAgency::HIBCC,
            humanReadable: '(01)04012345678901(10)LOT-2024-001',
        );

        self::assertSame('LOT-2024-001', $udi->lotNumber);
        self::assertSame('SN-12345', $udi->serialNumber);
        self::assertSame('2026-12-31', $udi->expirationDate);
        self::assertSame('2024-01-15', $udi->manufacturingDate);
        self::assertSame(UdiIssuingAgency::HIBCC, $udi->issuingAgency);
    }

    #[Test]
    public function fullUdiCombinesDiAndPiComponents(): void
    {
        $udi = new UdiIdentifier(
            deviceIdentifier: '04012345678901',
            lotNumber: 'LOT-001',
            serialNumber: 'SN-001',
            expirationDate: '2026-12-31',
            manufacturingDate: '2024-01-01',
        );

        self::assertSame(
            '04012345678901|LOT:LOT-001|SN:SN-001|EXP:2026-12-31|MFG:2024-01-01',
            $udi->fullUdi(),
        );
    }

    #[Test]
    public function fullUdiWithOnlyDi(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: '04012345678901');

        self::assertSame('04012345678901', $udi->fullUdi());
    }

    #[Test]
    public function fullUdiWithPartialPi(): void
    {
        $udi = new UdiIdentifier(
            deviceIdentifier: '04012345678901',
            lotNumber: 'LOT-001',
        );

        self::assertSame('04012345678901|LOT:LOT-001', $udi->fullUdi());
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: '04012345678901');
        $data = $udi->toArray();

        self::assertSame('04012345678901', $data['device_identifier']);
        self::assertSame('gs1', $data['issuing_agency']);
        self::assertArrayNotHasKey('lot_number', $data);
        self::assertArrayNotHasKey('serial_number', $data);
        self::assertArrayNotHasKey('expiration_date', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $udi = new UdiIdentifier(
            deviceIdentifier: '04012345678901',
            lotNumber: 'LOT-001',
            serialNumber: 'SN-001',
            expirationDate: '2026-12-31',
            manufacturingDate: '2024-01-01',
            issuingAgency: UdiIssuingAgency::ICCBBA,
            humanReadable: 'UDI string',
        );

        $data = $udi->toArray();

        self::assertSame('LOT-001', $data['lot_number']);
        self::assertSame('SN-001', $data['serial_number']);
        self::assertSame('2026-12-31', $data['expiration_date']);
        self::assertSame('2024-01-01', $data['manufacturing_date']);
        self::assertSame('iccbba', $data['issuing_agency']);
        self::assertSame('UDI string', $data['human_readable']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = new UdiIdentifier(
            deviceIdentifier: '04012345678901',
            lotNumber: 'LOT-001',
            serialNumber: 'SN-001',
            issuingAgency: UdiIssuingAgency::HIBCC,
        );

        $restored = UdiIdentifier::fromArray($original->toArray());

        self::assertSame($original->deviceIdentifier, $restored->deviceIdentifier);
        self::assertSame($original->lotNumber, $restored->lotNumber);
        self::assertSame($original->serialNumber, $restored->serialNumber);
        self::assertSame($original->issuingAgency, $restored->issuingAgency);
    }

    #[Test]
    public function fromArrayWithMinimalData(): void
    {
        $udi = UdiIdentifier::fromArray(['device_identifier' => 'DI-001']);

        self::assertSame('DI-001', $udi->deviceIdentifier);
        self::assertNull($udi->lotNumber);
        self::assertSame(UdiIssuingAgency::GS1, $udi->issuingAgency);
    }
}
