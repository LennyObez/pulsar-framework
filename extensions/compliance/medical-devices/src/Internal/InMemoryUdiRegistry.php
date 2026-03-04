<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRecord;
use Pulsar\Extension\MedicalDevices\Udi\DeviceRiskClass;
use Pulsar\Extension\MedicalDevices\Udi\UdiIdentifier;
use Pulsar\Extension\MedicalDevices\Udi\UdiRegistryInterface;

/**
 * In-memory UDI device registry for development and testing.
 *
 * Production deployments should provide a persistent implementation
 * backed by EUDAMED or a relational database.
 */
#[Internal(reason: 'Reference implementation; use UdiRegistryInterface for public API')]
final class InMemoryUdiRegistry implements UdiRegistryInterface
{
    /** @var array<string, array{udi: UdiIdentifier, device: DeviceRecord}> Keyed by device identifier */
    private array $devices = [];

    #[Override]
    public function register(UdiIdentifier $udi, DeviceRecord $device): void
    {
        $this->devices[$udi->deviceIdentifier] = ['udi' => $udi, 'device' => $device];
    }

    #[Override]
    public function findByDeviceIdentifier(string $deviceIdentifier): ?DeviceRecord
    {
        return $this->devices[$deviceIdentifier]['device'] ?? null;
    }

    #[Override]
    public function findByLotNumber(string $lotNumber): array
    {
        $results = [];

        foreach ($this->devices as $entry) {
            if ($entry['udi']->lotNumber === $lotNumber) {
                $results[] = $entry['device'];
            }
        }

        return $results;
    }

    #[Override]
    public function findBySerialNumber(string $serialNumber): ?DeviceRecord
    {
        foreach ($this->devices as $entry) {
            if ($entry['udi']->serialNumber === $serialNumber) {
                return $entry['device'];
            }
        }

        return null;
    }

    #[Override]
    public function listDevices(?DeviceRiskClass $riskClass = null): array
    {
        $results = [];

        foreach ($this->devices as $entry) {
            if ($riskClass === null || $entry['device']->riskClass === $riskClass) {
                $results[] = $entry['device'];
            }
        }

        return $results;
    }
}
