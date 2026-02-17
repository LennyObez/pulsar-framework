<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\WebAuthn\Attestation\AttestationResult;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;

/**
 * Attestation format verification contract.
 *
 * Verifies attestation statements from WebAuthn registration ceremonies.
 * Initially supports `none` and `packed` formats, with additional formats
 * (fido-u2f, android-key, apple) added based on demand.
 *
 * Attestation format policy is configurable; disallowed formats are rejected.
 */
#[Api(since: '1.0.0')]
interface AttestationVerifierInterface
{
    /**
     * Verify an attestation statement.
     *
     * @param string $format The attestation format (e.g., 'none', 'packed')
     * @param string $attestationObject The raw attestation object bytes
     * @param string $clientDataJson The client data JSON bytes
     * @return AttestationResult The verification result with trust path info
     *
     * @throws WebAuthnException If format is not allowed by policy
     */
    public function verify(
        string $format,
        string $attestationObject,
        string $clientDataJson,
    ): AttestationResult;

    /**
     * Check if an attestation format is allowed by the current policy.
     */
    public function isFormatAllowed(string $format): bool;

    /**
     * Get the list of attestation formats allowed by the current policy.
     *
     * @return list<string>
     */
    public function allowedFormats(): array;
}
