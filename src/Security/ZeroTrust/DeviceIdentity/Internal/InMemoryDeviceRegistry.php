<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity\Internal;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceIdentity;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceRegistryInterface;

use function array_filter;
use function array_values;
use function bin2hex;
use function hash_equals;
use function random_bytes;

/**
 * In-memory device registry implementation.
 *
 * Reference implementation suitable for testing and development.
 * Production applications should use a persistent storage backend.
 */
#[Internal]
final class InMemoryDeviceRegistry implements DeviceRegistryInterface
{
    /** @var array<string, DeviceIdentity> deviceId => DeviceIdentity */
    private array $devices = [];

    /** @var array<string, string> deviceId => pending challenge */
    private array $pendingChallenges = [];

    public function __construct(
        private readonly KeyRingInterface $keyRing,
        private readonly string $keyId = 'device-registry',
    ) {}

    public function register(
        string $identityId,
        string $fingerprint,
        string $attestationType,
        string $publicKey,
        array $metadata = [],
    ): DeviceIdentity {
        $deviceId = bin2hex(random_bytes(16));

        $device = new DeviceIdentity(
            deviceId: $deviceId,
            identityId: $identityId,
            fingerprint: $fingerprint,
            attestationType: $attestationType,
            publicKey: $publicKey,
            registeredAt: new DateTimeImmutable(),
            metadata: $metadata,
        );

        $this->devices[$deviceId] = $device;

        return $device;
    }

    public function find(string $deviceId): ?DeviceIdentity
    {
        return $this->devices[$deviceId] ?? null;
    }

    /**
     * @return list<DeviceIdentity>
     */
    public function findByIdentity(string $identityId): array
    {
        return array_values(
            array_filter(
                $this->devices,
                static fn(DeviceIdentity $d): bool => $d->identityId === $identityId,
            ),
        );
    }

    public function verify(string $deviceId, string $challenge, string $proof): DeviceProofResult
    {
        $device = $this->find($deviceId);

        if ($device === null) {
            return DeviceProofResult::failed('Device not found');
        }

        $pendingChallenge = $this->pendingChallenges[$deviceId] ?? null;

        if ($pendingChallenge === null || !hash_equals($pendingChallenge, $challenge)) {
            return DeviceProofResult::failed('Invalid or expired challenge');
        }

        // Remove the challenge to prevent replay
        unset($this->pendingChallenges[$deviceId]);

        // Verify the proof: the proof should be an HMAC of the challenge using the device's key material
        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            return DeviceProofResult::failed('Device verification key unavailable');
        }

        $expectedProof = Hmac::computeHex($challenge . $device->publicKey, $key);

        if (!hash_equals($expectedProof, $proof)) {
            return DeviceProofResult::failed('Proof verification failed');
        }

        // Update last verified timestamp
        $this->devices[$deviceId] = new DeviceIdentity(
            deviceId: $device->deviceId,
            identityId: $device->identityId,
            fingerprint: $device->fingerprint,
            attestationType: $device->attestationType,
            publicKey: $device->publicKey,
            registeredAt: $device->registeredAt,
            lastVerifiedAt: new DateTimeImmutable(),
            metadata: $device->metadata,
        );

        return DeviceProofResult::verified($deviceId);
    }

    public function revoke(string $deviceId): void
    {
        unset($this->devices[$deviceId], $this->pendingChallenges[$deviceId]);
    }

    /**
     * Issue a challenge for a device. The challenge must be verified within the same session.
     */
    public function issueChallenge(string $deviceId): ?string
    {
        if (!isset($this->devices[$deviceId])) {
            return null;
        }

        $nonce = bin2hex(random_bytes(32));
        $timestamp = (string) time();
        $challenge = $nonce . '|' . $timestamp;

        $this->pendingChallenges[$deviceId] = $challenge;

        return $challenge;
    }

    /**
     * Generate a valid proof for a challenge. Used internally for testing verification flow.
     */
    public function generateProof(string $deviceId, string $challenge): ?string
    {
        $device = $this->find($deviceId);

        if ($device === null) {
            return null;
        }

        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            return null;
        }

        return Hmac::computeHex($challenge . $device->publicKey, $key);
    }
}
