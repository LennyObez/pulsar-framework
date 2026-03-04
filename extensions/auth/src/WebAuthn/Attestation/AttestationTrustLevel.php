<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Attestation;

use Pulsar\Api\Api;

/**
 * Trust level determined by attestation verification.
 */
#[Api(since: '1.0.0')]
enum AttestationTrustLevel: string
{
    /** No attestation provided (format = 'none'). */
    case None = 'none';

    /** Self-attestation (key proves itself, no CA trust chain). */
    case Self = 'self';

    /** Basic attestation (attestation key from the same CA as the authenticator). */
    case Basic = 'basic';

    /** Attestation CA (attestation key from a trusted certification authority). */
    case AttestationCa = 'attestation_ca';
}
