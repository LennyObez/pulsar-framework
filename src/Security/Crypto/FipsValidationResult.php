<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Structured result of a FIPS 140-2 compliance validation.
 */
#[Api(since: '1.0.0')]
final readonly class FipsValidationResult
{
    /**
     * @param bool          $compliant           Whether the environment is fully FIPS 140-2 compliant
     * @param bool          $fipsDetected        Whether OpenSSL FIPS mode was detected
     * @param bool          $aes256GcmAvailable  Whether AES-256-GCM is available
     * @param bool          $hmacSha256Available Whether HMAC-SHA-256 is available
     * @param string        $opensslVersion      The OpenSSL version string
     * @param list<string>  $warnings            Human-readable warnings for non-compliance
     */
    public function __construct(
        public bool $compliant,
        public bool $fipsDetected,
        public bool $aes256GcmAvailable,
        public bool $hmacSha256Available,
        public string $opensslVersion,
        public array $warnings,
    ) {}

    /**
     * Get a human-readable summary of the validation result.
     */
    #[NoDiscard]
    public function summary(): string
    {
        if ($this->compliant) {
            return 'FIPS 140-2 compliant: all requirements met';
        }

        return 'FIPS 140-2 not compliant: ' . implode('; ', $this->warnings);
    }
}
