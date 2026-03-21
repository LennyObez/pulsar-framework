<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity;

use Pulsar\Api\Api;

/**
 * Contract for the zero-trust device registry.
 *
 * Manages device enrollment, lookup, and cryptographic proof verification.
 * Implementations handle the storage backend and attestation format specifics
 * (e.g., WebAuthn, client certificates).
 * @api
 */
#[Api(since: '1.0.0')]
interface DeviceRegistryInterface
{
    /**
     * Register a new device for an identity.
     *
     * @param string $identityId The identity this device belongs to
     * @param string $fingerprint Hashed device fingerprint
     * @param string $attestationType Attestation format (e.g., "webauthn", "client_cert")
     * @param string $publicKey Device public key for future proof verification
     * @param array<string, mixed> $metadata Additional device metadata
     */
    public function register(
        string $identityId,
        string $fingerprint,
        string $attestationType,
        string $publicKey,
        array $metadata = [],
    ): DeviceIdentity;

    /**
     * Look up a device by its identifier.
     */
    public function find(string $deviceId): ?DeviceIdentity;

    /**
     * Find all devices registered to an identity.
     *
     * @return list<DeviceIdentity>
     */
    public function findByIdentity(string $identityId): array;

    /**
     * Verify a device proof (e.g., WebAuthn assertion, signed challenge).
     *
     * @param string $deviceId Device to verify against
     * @param string $challenge The challenge that was issued
     * @param string $proof The signed proof from the device
     */
    public function verify(string $deviceId, string $challenge, string $proof): DeviceProofResult;

    /**
     * Remove a device from the registry.
     */
    public function revoke(string $deviceId): void;
}
