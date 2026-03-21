<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Registered device data model.
 *
 * Represents a device that has been enrolled in the zero-trust device registry.
 * Stores the device fingerprint, attestation data, and registration metadata.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DeviceIdentity
{
    /**
     * @param string $deviceId Unique device identifier
     * @param string $identityId Owner identity this device is bound to
     * @param string $fingerprint Device fingerprint (hashed, not raw)
     * @param string $attestationType Type of attestation used (e.g., "webauthn", "client_cert")
     * @param string $publicKey Public key for device proof verification (PEM or base64-encoded)
     * @param DateTimeImmutable $registeredAt When the device was registered
     * @param DateTimeImmutable|null $lastVerifiedAt Last successful device verification
     * @param array<string, mixed> $metadata Additional device metadata (OS, browser, etc.)
     */
    public function __construct(
        public string $deviceId,
        public string $identityId,
        public string $fingerprint,
        public string $attestationType,
        public string $publicKey,
        public DateTimeImmutable $registeredAt,
        public ?DateTimeImmutable $lastVerifiedAt = null,
        public array $metadata = [],
    ) {}
}
