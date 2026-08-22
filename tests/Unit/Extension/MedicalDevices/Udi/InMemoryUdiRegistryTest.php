<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Udi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Internal\InMemoryUdiRegistry;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRecord;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRiskClass;
use Pulsar\Extension\MedicalDevices\Udi\UdiIdentifier;

#[CoversClass(InMemoryUdiRegistry::class)]
final class InMemoryUdiRegistryTest extends TestCase
{
    private InMemoryUdiRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new InMemoryUdiRegistry();
    }

    /** @return array{0: UdiIdentifier, 1: DeviceRecord} */
    private function createDevice(string $di, DeviceRiskClass $class = DeviceRiskClass::ClassIIa, ?string $lot = null, ?string $serial = null): array
    {
        $udi = new UdiIdentifier(deviceIdentifier: $di, lotNumber: $lot, serialNumber: $serial);
        $device = new DeviceRecord(
            udi: $udi,
            deviceName: "Device $di",
            manufacturer: 'TestMfg',
            riskClass: $class,
        );

        return [$udi, $device];
    }

    #[Test]
    public function registersAndFindsByDeviceIdentifier(): void
    {
        [$udi, $device] = $this->createDevice('DI-001');
        $this->registry->register($udi, $device);

        $found = $this->registry->findByDeviceIdentifier('DI-001');

        self::assertSame($device, $found);
    }

    #[Test]
    public function returnsNullForUnknownDeviceIdentifier(): void
    {
        self::assertNull($this->registry->findByDeviceIdentifier('UNKNOWN'));
    }

    #[Test]
    public function findsByLotNumber(): void
    {
        [$udi1, $device1] = $this->createDevice('DI-001', lot: 'LOT-A');
        [$udi2, $device2] = $this->createDevice('DI-002', lot: 'LOT-A');
        [$udi3, $device3] = $this->createDevice('DI-003', lot: 'LOT-B');

        $this->registry->register($udi1, $device1);
        $this->registry->register($udi2, $device2);
        $this->registry->register($udi3, $device3);

        $results = $this->registry->findByLotNumber('LOT-A');

        self::assertCount(2, $results);
        self::assertContains($device1, $results);
        self::assertContains($device2, $results);
    }

    #[Test]
    public function findByLotReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->registry->findByLotNumber('NONEXISTENT'));
    }

    #[Test]
    public function findsBySerialNumber(): void
    {
        [$udi, $device] = $this->createDevice('DI-001', serial: 'SN-12345');
        $this->registry->register($udi, $device);

        $found = $this->registry->findBySerialNumber('SN-12345');

        self::assertSame($device, $found);
    }

    #[Test]
    public function returnsNullForUnknownSerialNumber(): void
    {
        self::assertNull($this->registry->findBySerialNumber('UNKNOWN'));
    }

    #[Test]
    public function listsAllDevices(): void
    {
        [$udi1, $device1] = $this->createDevice('DI-001', DeviceRiskClass::ClassI);
        [$udi2, $device2] = $this->createDevice('DI-002', DeviceRiskClass::ClassIII);

        $this->registry->register($udi1, $device1);
        $this->registry->register($udi2, $device2);

        $all = $this->registry->listDevices();

        self::assertCount(2, $all);
    }

    #[Test]
    public function listsDevicesFilteredByRiskClass(): void
    {
        [$udi1, $device1] = $this->createDevice('DI-001', DeviceRiskClass::ClassI);
        [$udi2, $device2] = $this->createDevice('DI-002', DeviceRiskClass::ClassIII);
        [$udi3, $device3] = $this->createDevice('DI-003', DeviceRiskClass::ClassIII);

        $this->registry->register($udi1, $device1);
        $this->registry->register($udi2, $device2);
        $this->registry->register($udi3, $device3);

        $classIII = $this->registry->listDevices(DeviceRiskClass::ClassIII);

        self::assertCount(2, $classIII);
        self::assertContains($device2, $classIII);
        self::assertContains($device3, $classIII);
    }

    #[Test]
    public function listDevicesReturnsEmptyWhenNoDevicesRegistered(): void
    {
        self::assertSame([], $this->registry->listDevices());
    }

    #[Test]
    public function overwritesDeviceWithSameDi(): void
    {
        [$udi, $device1] = $this->createDevice('DI-001', DeviceRiskClass::ClassI);
        $this->registry->register($udi, $device1);

        $device2 = new DeviceRecord(
            udi: $udi,
            deviceName: 'Updated Device',
            manufacturer: 'NewMfg',
            riskClass: DeviceRiskClass::ClassIII,
        );
        $this->registry->register($udi, $device2);

        $found = $this->registry->findByDeviceIdentifier('DI-001');
        self::assertSame('Updated Device', $found?->deviceName);
        self::assertCount(1, $this->registry->listDevices());
    }
}
