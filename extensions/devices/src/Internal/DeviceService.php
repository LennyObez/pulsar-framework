<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Internal;

use Pulsar\Api\Internal;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;
use Pulsar\Extension\Devices\UserDeviceRepositoryInterface;
use RuntimeException;

use function bin2hex;
use function random_bytes;
use function sodium_crypto_generichash;

/**
 * Core device management logic: registration, token rotation, removal, and authentication.
 *
 * Tokens are generated as 64-byte random hex strings and stored only as BLAKE2b hashes.
 * The raw token is returned exactly once (on creation or rotation) and never persisted.
 */
#[Internal(reason: 'Device management service — use DeviceService via the container')]
final readonly class DeviceService
{
    public function __construct(
        private UserDeviceRepositoryInterface $devices,
        private int $maxDevicesPerUser = 5,
    ) {}

    /**
     * List all devices belonging to a user.
     *
     * @return list<UserDevice>
     */
    public function listDevices(string $userId): array
    {
        return $this->devices->findByUser($userId);
    }

    /**
     * Register a new device for a user.
     *
     * Enforces the per-user device limit. Generates a cryptographically random
     * API token, hashes it with BLAKE2b, and persists the device with the hash.
     *
     * @return array{device: UserDevice, token: string} The device entity and the raw token (shown once)
     *
     * @throws RuntimeException If the user has reached the maximum device limit
     */
    public function register(
        string $userId,
        string $deviceName,
        Platform $platform,
        string $appVersion,
    ): array {
        $count = $this->devices->countByUser($userId);

        if ($count >= $this->maxDevicesPerUser) {
            throw new RuntimeException(
                "Device limit reached: user $userId already has $count registered devices (max $this->maxDevicesPerUser)",
            );
        }

        $rawToken = bin2hex(random_bytes(64));
        $hash = bin2hex(sodium_crypto_generichash($rawToken));

        $device = UserDevice::create(
            userId: $userId,
            deviceName: $deviceName,
            platform: $platform,
            appVersion: $appVersion,
            apiTokenHash: $hash,
        );

        $this->devices->save($device);

        return ['device' => $device, 'token' => $rawToken];
    }

    /**
     * Rotate the API token for an existing device.
     *
     * Verifies that the device belongs to the requesting user before rotating.
     * Generates a new token, updates the stored hash, and returns the new raw token.
     *
     * @return array{device: UserDevice, token: string} The updated device and the new raw token
     *
     * @throws RuntimeException If the device is not found or does not belong to the user
     */
    public function rotateToken(string $deviceId, string $userId): array
    {
        $device = $this->devices->findById($deviceId);

        if ($device === null || $device->userId !== $userId) {
            throw new RuntimeException(
                "Device $deviceId not found or does not belong to user $userId",
            );
        }

        $rawToken = bin2hex(random_bytes(64));
        $hash = bin2hex(sodium_crypto_generichash($rawToken));

        $updated = $device->rotateToken($hash);
        $this->devices->save($updated);

        return ['device' => $updated, 'token' => $rawToken];
    }

    /**
     * Remove a device registration.
     *
     * Verifies ownership before deletion to prevent unauthorized removal.
     *
     * @throws RuntimeException If the device is not found or does not belong to the user
     */
    public function remove(string $deviceId, string $userId): void
    {
        $device = $this->devices->findById($deviceId);

        if ($device === null || $device->userId !== $userId) {
            throw new RuntimeException(
                "Device $deviceId not found or does not belong to user $userId",
            );
        }

        $this->devices->delete($deviceId);
    }

    /**
     * Authenticate a request by raw API token.
     *
     * Hashes the provided token with BLAKE2b, looks up the device by hash,
     * and updates lastSeenAt on match. Returns null if the token is invalid.
     */
    public function authenticate(string $rawToken): ?UserDevice
    {
        $hash = bin2hex(sodium_crypto_generichash($rawToken));

        $device = $this->devices->findByTokenHash($hash);

        if ($device === null) {
            return null;
        }

        $updated = $device->updateLastSeen();
        $this->devices->save($updated);

        return $updated;
    }
}
