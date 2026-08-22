<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

/**
 * Contract for UDI device registration and tracking.
 *
 * Implementations may store device records in a database or proxy
 * to EUDAMED or other device registries.
 * @api
 */
#[Api(since: '1.0.0')]
interface UdiRegistryInterface
{
    /**
     * Register a device with its UDI.
     */
    public function register(UdiIdentifier $udi, DeviceRecord $device): void;

    /**
     * Look up a device by its device identifier (DI).
     */
    public function findByDeviceIdentifier(string $deviceIdentifier): ?DeviceRecord;

    /**
     * Look up all devices matching a lot number.
     *
     * @return list<DeviceRecord>
     */
    public function findByLotNumber(string $lotNumber): array;

    /**
     * Look up a device by its serial number.
     */
    public function findBySerialNumber(string $serialNumber): ?DeviceRecord;

    /**
     * List all registered devices, optionally filtered by risk class.
     *
     * @return list<DeviceRecord>
     */
    public function listDevices(?DeviceRiskClass $riskClass = null): array;
}
