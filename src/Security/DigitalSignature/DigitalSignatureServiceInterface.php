<?php

declare(strict_types=1);

namespace Pulsar\Security\DigitalSignature;

use Pulsar\Api\Api;

/**
 * Service for creating and verifying electronic signatures per eIDAS Articles 25-34.
 *
 * Implementations wrap external libraries or HSM/TSP integrations to produce
 * advanced or qualified electronic signatures in standard formats (XAdES, PAdES, CAdES, JAdES).
 * @api
 */
#[Api(since: '1.0.0')]
interface DigitalSignatureServiceInterface
{
    /**
     * Sign data using the configured signing key and format.
     *
     * @param string          $data   The data to sign
     * @param SignatureFormat  $format The signature format to produce
     *
     * @return string The signature bytes (format-specific encoding)
     */
    public function sign(string $data, SignatureFormat $format = SignatureFormat::CAdES): string;

    /**
     * Verify a signature against the original data.
     *
     * Validates the cryptographic signature and optionally checks the
     * certificate chain against configured trust anchors.
     *
     * @param string          $data      The original signed data
     * @param string          $signature The signature to verify
     * @param SignatureFormat  $format    The expected signature format
     *
     * @return SignatureInfo Verification result with signer metadata
     */
    public function verify(string $data, string $signature, SignatureFormat $format = SignatureFormat::CAdES): SignatureInfo;

    /**
     * Return the signature formats supported by this implementation.
     *
     * @return list<SignatureFormat>
     */
    public function supportedFormats(): array;
}
