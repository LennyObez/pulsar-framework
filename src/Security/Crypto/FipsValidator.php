<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use NoDiscard;
use Pulsar\Api\Api;

use function array_intersect;
use function in_array;
use function openssl_get_cipher_methods;
use function str_contains;
use function strtolower;

use const OPENSSL_VERSION_TEXT;

/**
 * Validates that the runtime environment meets FIPS 140-2 requirements.
 *
 * FIPS 140-2 compatible: uses FIPS 140-2 approved algorithms (AES-256-GCM,
 * HMAC-SHA-256). Achieves FIPS 140-2 compliance when deployed with a
 * NIST-validated OpenSSL FIPS provider. Use FipsValidator::verify() to
 * confirm your deployment meets FIPS requirements.
 * @api
 */
#[Api(since: '1.0.0')]
final class FipsValidator
{
    /**
     * The cipher Pulsar uses for encryption.
     */
    private const string REQUIRED_CIPHER = 'aes-256-gcm';

    /**
     * Verify that the current runtime meets FIPS 140-2 requirements.
     *
     * Returns a structured result indicating whether FIPS mode is active,
     * whether the required algorithms are available, and any warnings.
     *
     * @return FipsValidationResult
     */
    #[NoDiscard]
    public static function verify(): FipsValidationResult
    {
        $fipsDetected = self::isFipsAvailable();
        $aes256GcmAvailable = self::isAes256GcmAvailable();
        $hmacSha256Available = self::isHmacSha256Available();
        $opensslVersion = OPENSSL_VERSION_TEXT;

        $warnings = [];

        if (!$fipsDetected) {
            $warnings[] = 'OpenSSL FIPS mode is not detected. For full FIPS 140-2 compliance, '
                . 'deploy with a NIST-validated OpenSSL FIPS provider and enable FIPS mode.';
        }

        if (!$aes256GcmAvailable) {
            $warnings[] = 'AES-256-GCM is not available in the current OpenSSL build. '
                . 'This cipher is required for Pulsar encryption.';
        }

        if (!$hmacSha256Available) {
            $warnings[] = 'HMAC-SHA-256 is not available in the current hash algorithms.';
        }

        $compliant = $fipsDetected && $aes256GcmAvailable && $hmacSha256Available;

        return new FipsValidationResult(
            compliant: $compliant,
            fipsDetected: $fipsDetected,
            aes256GcmAvailable: $aes256GcmAvailable,
            hmacSha256Available: $hmacSha256Available,
            opensslVersion: $opensslVersion,
            warnings: $warnings,
        );
    }

    /**
     * Check if the OpenSSL build has FIPS mode enabled/available.
     *
     * Detection strategies:
     * 1. Check OPENSSL_VERSION_TEXT for FIPS indicators
     * 2. Check for the presence of the FIPS provider via cipher availability
     */
    #[NoDiscard]
    public static function isFipsAvailable(): bool
    {
        $versionText = strtolower(OPENSSL_VERSION_TEXT);

        // Check for explicit FIPS indicators in the version string
        if (str_contains($versionText, 'fips')) {
            return true;
        }

        // OpenSSL 3.x uses providers. In FIPS mode, only FIPS-approved
        // algorithms are available. Check if non-FIPS algorithms are absent.
        // If the system has a FIPS provider active, algorithms like chacha20
        // and blowfish will not be available.
        $ciphers = openssl_get_cipher_methods(true);

        // These are never FIPS-approved. If none are present, FIPS is likely enforced.
        $nonFipsCiphers = ['chacha20-poly1305', 'bf-cbc', 'bf-ecb', 'rc4', 'des-ecb'];
        $foundNonFips = array_intersect($nonFipsCiphers, $ciphers);

        // If no non-FIPS ciphers are available and AES-GCM is, FIPS provider is active
        if ($foundNonFips === [] && in_array(self::REQUIRED_CIPHER, $ciphers, true)) {
            return true;
        }

        return false;
    }

    /**
     * Check whether AES-256-GCM is available via OpenSSL.
     */
    #[NoDiscard]
    public static function isAes256GcmAvailable(): bool
    {
        return in_array(self::REQUIRED_CIPHER, openssl_get_cipher_methods(true), true);
    }

    /**
     * Check whether HMAC-SHA-256 is available.
     */
    #[NoDiscard]
    public static function isHmacSha256Available(): bool
    {
        return in_array('sha256', hash_hmac_algos(), true);
    }
}
