<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices;

use Pulsar\Api\Api;

/**
 * Repository interface for user device persistence.
 * @api
 */
#[Api(since: '1.0.0')]
interface UserDeviceRepositoryInterface
{
    /**
     * Persist a device (insert or update).
     */
    public function save(UserDevice $device): void;

    /**
     * Find a device by its unique identifier.
     */
    public function findById(string $id): ?UserDevice;

    /**
     * Find all devices belonging to a user.
     *
     * @return list<UserDevice>
     */
    public function findByUser(string $userId): array;

    /**
     * Find a device by its API token hash.
     */
    public function findByTokenHash(string $hash): ?UserDevice;

    /**
     * Delete a device by its unique identifier.
     */
    public function delete(string $id): void;

    /**
     * Count the number of devices registered for a user.
     */
    public function countByUser(string $userId): int;
}
