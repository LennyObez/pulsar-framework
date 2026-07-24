<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Revocation;

use Pulsar\Api\Internal;

/**
 * Resolves the revocation status of an end-entity certificate against its
 * issuer, using one or more mechanisms (OCSP, CRL).
 *
 * Implementations MUST return {@see RevocationStatus::Unknown} — never
 * {@see RevocationStatus::Good} — whenever they cannot obtain a
 * cryptographically verified verdict. The validator's fail-open/fail-closed
 * policy is applied by the caller, not here.
 */
#[Internal(reason: 'Certificate revocation strategy for the PSD2 validator')]
interface RevocationCheckerInterface
{
    public function check(string $leafPem, string $issuerPem): RevocationStatus;
}
