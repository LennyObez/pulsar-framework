<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Privacy\Internal;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\ZeroTrust\Privacy\PseudonymizerInterface;

use function sprintf;
use function substr;

/**
 * Pseudonymizes identifiers using HMAC with rotation salt.
 *
 * Produces deterministic, irreversible pseudonyms for privacy-preserving analytics and audit.
 * The same value + context always yields the same pseudonym within a rotation period.
 * Rotation salt changes periodically (configurable) to limit correlation windows.
 *
 * All key material is sourced from KeyRingInterface.
 */
#[Internal]
final readonly class HmacPseudonymizer implements PseudonymizerInterface
{
    public function __construct(
        private KeyRingInterface $keyRing,
        private string $keyId = 'pseudonymizer',
        private string $rotationSalt = '',
    ) {}

    public function pseudonymize(string $value, string $context): string
    {
        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            // Return a non-reversible placeholder when key is unavailable.
            // This ensures callers never see raw identifiers in logs.
            return sprintf('pseudo:%s:unavailable', $context);
        }

        // Domain-separated HMAC: context ensures different pseudonyms for same value in different domains
        $message = $context . '|' . $this->rotationSalt . '|' . $value;
        $hash = Hmac::computeHex($message, $key);

        // Return a truncated pseudonym (first 16 hex chars = 64 bits) prefixed with context
        return sprintf('pseudo:%s:%s', $context, substr($hash, 0, 16));
    }

    /**
     * Create a new pseudonymizer with a different rotation salt.
     *
     * Used when rotating the salt periodically to limit correlation windows.
     */
    #[NoDiscard]
    public function withRotationSalt(string $salt): self
    {
        return new self($this->keyRing, $this->keyId, $salt);
    }
}
