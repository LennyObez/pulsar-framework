<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;

/**
 * Pluggable cipher suite abstraction for FIPS compliance.
 *
 * Allows swapping between sodium (default) and AES-GCM cipher suites
 * without changing calling code. Each implementation handles authenticated
 * encryption with optional associated data (AEAD) and keyed HMAC.
 *
 * FIPS 140-2 compatible: uses FIPS 140-2 approved algorithms (AES-256-GCM,
 * HMAC-SHA-256) via the AesGcmCipherSuite implementation. Achieves FIPS 140-2
 * compliance when deployed with a NIST-validated OpenSSL FIPS provider.
 * Use FipsValidator::verify() to confirm your deployment meets FIPS requirements.
 * @api
 */
#[Api(since: '1.0.0')]
interface CipherSuiteInterface
{
    /**
     * Encrypt plaintext with the given key and optional associated data.
     *
     * Returns raw ciphertext bytes prefixed with a version byte.
     *
     * @throws SecurityException If encryption fails
     */
    public function encrypt(string $plaintext, string $key, string $aad = ''): string;

    /**
     * Decrypt ciphertext previously produced by encrypt().
     *
     * @throws SecurityException If decryption fails (wrong key, tampered data, AAD mismatch)
     */
    public function decrypt(string $ciphertext, string $key, string $aad = ''): string;

    /**
     * Compute a keyed MAC and return raw bytes.
     */
    public function hmac(string $data, string $key): string;

    /**
     * Compute a keyed MAC and return as hex string.
     */
    public function hmacHex(string $data, string $key): string;

    /**
     * Verify a keyed MAC using constant-time comparison.
     */
    public function verifyHmac(string $data, string $expected, string $key): bool;

    /**
     * Return the cipher suite identifier (e.g. 'sodium', 'aes-gcm').
     */
    public function name(): string;
}
