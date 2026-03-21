<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a device identity verification.
 *
 * Indicates whether the device proof (e.g., WebAuthn attestation, client certificate)
 * was successfully verified, along with a confidence score and failure reason if applicable.
 */
#[Api(since: '1.0.0')]
final readonly class DeviceProofResult
{
    /**
     * @param bool $verified Whether the device proof passed verification
     * @param float $confidence Confidence in the verification (0.0-1.0)
     * @param string $reason Human-readable explanation (populated on failure)
     * @param string $deviceId Verified device identifier (populated on success)
     */
    public function __construct(
        public bool $verified,
        public float $confidence,
        public string $reason = '',
        public string $deviceId = '',
    ) {}

    /**
     * Create a successful verification result.
     */
    #[NoDiscard]
    public static function verified(string $deviceId, float $confidence = 1.0): self
    {
        return new self(
            verified: true,
            confidence: $confidence,
            deviceId: $deviceId,
        );
    }

    /**
     * Create a failed verification result.
     */
    #[NoDiscard]
    public static function failed(string $reason, float $confidence = 0.0): self
    {
        return new self(
            verified: false,
            confidence: $confidence,
            reason: $reason,
        );
    }
}
