<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function bin2hex;
use function random_bytes;

/**
 * Represents a registered user device with an associated API token.
 *
 * Devices are immutable value objects. State transitions (last-seen updates,
 * token rotations) produce new instances via clone-with.
 */
#[Api(since: '1.0.0')]
final readonly class UserDevice
{
    /**
     * @param string $id Hex device identifier (32 chars)
     * @param string $userId FK auth_users — the device owner
     * @param string $deviceName Human-readable device label
     * @param Platform $platform Operating system / platform
     * @param string $appVersion Application version string
     * @param string $apiTokenHash BLAKE2b hash of the raw API token (hex-encoded)
     * @param DateTimeImmutable|null $lastSeenAt Last authenticated request timestamp
     * @param DateTimeImmutable $createdAt When the device was registered
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $deviceName,
        public Platform $platform,
        public string $appVersion,
        public string $apiTokenHash,
        public ?DateTimeImmutable $lastSeenAt,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a new device registration.
     */
    public static function create(
        string $userId,
        string $deviceName,
        Platform $platform,
        string $appVersion,
        string $apiTokenHash,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            userId: $userId,
            deviceName: $deviceName,
            platform: $platform,
            appVersion: $appVersion,
            apiTokenHash: $apiTokenHash,
            lastSeenAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    /**
     * Record the most recent authenticated access.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function updateLastSeen(): self
    {
        return clone($this, [
            'lastSeenAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Replace the API token hash after a token rotation.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function rotateToken(string $newHash): self
    {
        return clone($this, [
            'apiTokenHash' => $newHash,
        ]);
    }
}
