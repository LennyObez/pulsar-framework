<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Internal;

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

    #[Test]
    public function findByDeviceIdentifierReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->registry->findByDeviceIdentifier('DI-001'));
    }

    #[Test]
    public function registerAndFindByDeviceIdentifier(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: 'DI-001');
        $record = $this->createRecord($udi);

        $this->registry->register($udi, $record);

        $found = $this->registry->findByDeviceIdentifier('DI-001');
        self::assertNotNull($found);
        self::assertSame('Test Device', $found->deviceName);
    }

    #[Test]
    public function registerOverwritesPreviousEntry(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: 'DI-001');
        $this->registry->register($udi, $this->createRecord($udi, name: 'First'));
        $this->registry->register($udi, $this->createRecord($udi, name: 'Second'));

        $found = $this->registry->findByDeviceIdentifier('DI-001');
        self::assertSame('Second', $found->deviceName);
    }

    #[Test]
    public function findByLotNumber(): void
    {
        $udi1 = new UdiIdentifier(deviceIdentifier: 'DI-001', lotNumber: 'LOT-A');
        $udi2 = new UdiIdentifier(deviceIdentifier: 'DI-002', lotNumber: 'LOT-A');
        $udi3 = new UdiIdentifier(deviceIdentifier: 'DI-003', lotNumber: 'LOT-B');

        $this->registry->register($udi1, $this->createRecord($udi1, name: 'Device 1'));
        $this->registry->register($udi2, $this->createRecord($udi2, name: 'Device 2'));
        $this->registry->register($udi3, $this->createRecord($udi3, name: 'Device 3'));

        $lotA = $this->registry->findByLotNumber('LOT-A');
        self::assertCount(2, $lotA);

        $lotB = $this->registry->findByLotNumber('LOT-B');
        self::assertCount(1, $lotB);
    }

    #[Test]
    public function findByLotNumberReturnsEmptyWhenNoMatch(): void
    {
        self::assertSame([], $this->registry->findByLotNumber('NONEXISTENT'));
    }

    #[Test]
    public function findBySerialNumber(): void
    {
        $udi = new UdiIdentifier(deviceIdentifier: 'DI-001', serialNumber: 'SN-999');
        $this->registry->register($udi, $this->createRecord($udi));

        $found = $this->registry->findBySerialNumber('SN-999');
        self::assertNotNull($found);
        self::assertSame('Test Device', $found->deviceName);
    }

    #[Test]
    public function findBySerialNumberReturnsNullWhenNoMatch(): void
    {
        self::assertNull($this->registry->findBySerialNumber('NONEXISTENT'));
    }

    #[Test]
    public function listDevicesReturnsAll(): void
    {
        $udi1 = new UdiIdentifier(deviceIdentifier: 'DI-001');
        $udi2 = new UdiIdentifier(deviceIdentifier: 'DI-002');

        $this->registry->register($udi1, $this->createRecord($udi1, riskClass: DeviceRiskClass::ClassI));
        $this->registry->register($udi2, $this->createRecord($udi2, riskClass: DeviceRiskClass::ClassIII));

        self::assertCount(2, $this->registry->listDevices());
    }

    #[Test]
    public function listDevicesFiltersByRiskClass(): void
    {
        $udi1 = new UdiIdentifier(deviceIdentifier: 'DI-001');
        $udi2 = new UdiIdentifier(deviceIdentifier: 'DI-002');
        $udi3 = new UdiIdentifier(deviceIdentifier: 'DI-003');

        $this->registry->register($udi1, $this->createRecord($udi1, riskClass: DeviceRiskClass::ClassI));
        $this->registry->register($udi2, $this->createRecord($udi2, riskClass: DeviceRiskClass::ClassIII));
        $this->registry->register($udi3, $this->createRecord($udi3, riskClass: DeviceRiskClass::ClassIII));

        $classIII = $this->registry->listDevices(DeviceRiskClass::ClassIII);
        self::assertCount(2, $classIII);

        $classI = $this->registry->listDevices(DeviceRiskClass::ClassI);
        self::assertCount(1, $classI);
    }

    #[Test]
    public function listDevicesReturnsEmptyWhenEmpty(): void
    {
        self::assertSame([], $this->registry->listDevices());
    }

    private function createRecord(
        UdiIdentifier $udi,
        string $name = 'Test Device',
        DeviceRiskClass $riskClass = DeviceRiskClass::ClassIIa,
    ): DeviceRecord {
        return new DeviceRecord(
            udi: $udi,
            deviceName: $name,
            manufacturer: 'TestCo',
            riskClass: $riskClass,
        );
    }
}
