<?php

declare(strict_types=1);

namespace Pulsar\Security\DigitalSignature;

use Pulsar\Api\Api;

/**
 * Service for creating and verifying electronic seals per eIDAS Articles 35-40.
 *
 * Electronic seals are organization-level (not personal) signatures used to
 * guarantee the origin and integrity of documents. They serve a similar purpose
 * to a corporate stamp but in digital form.
 */
#[Api(since: '1.0.0')]
interface ElectronicSealServiceInterface
{
    /**
     * Seal data with the organization's electronic seal.
     *
     * @param string         $data   The data to seal
     * @param SignatureFormat $format The seal format to produce
     *
     * @return string The seal bytes (format-specific encoding)
     */
    public function seal(string $data, SignatureFormat $format = SignatureFormat::CAdES): string;

    /**
     * Verify an electronic seal against the original data.
     *
     * @param string         $data   The original sealed data
     * @param string         $seal   The seal to verify
     * @param SignatureFormat $format The expected seal format
     *
     * @return SignatureInfo Verification result with organization metadata
     */
    public function verifySeal(string $data, string $seal, SignatureFormat $format = SignatureFormat::CAdES): SignatureInfo;
}
