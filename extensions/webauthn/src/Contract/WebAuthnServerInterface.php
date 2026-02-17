<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationOptions;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationResult;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationOptions;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationResult;

/**
 * Top-level WebAuthn ceremony orchestrator.
 *
 * Handles WebAuthn registration (attestation) and authentication (assertion)
 * ceremonies. Implementations wrap a proven WebAuthn library behind this port.
 *
 * All ceremonies use challenge-response pattern with one-time challenges.
 * All ceremony events are audit-logged (Finding D).
 */
#[Api(since: '1.0.0')]
interface WebAuthnServerInterface
{
    /**
     * Generate options for a registration (attestation) ceremony.
     *
     * Creates a new challenge and returns the PublicKeyCredentialCreationOptions
     * to send to the client.
     *
     * @param string $userId The internal user identifier
     * @param string $userName The user's display name for the authenticator
     * @param list<string> $excludeCredentialIds Credential IDs to exclude (already registered)
     */
    public function generateRegistrationOptions(
        string $userId,
        string $userName,
        array $excludeCredentialIds = [],
    ): RegistrationOptions;

    /**
     * Verify a registration (attestation) ceremony response.
     *
     * Validates the attestation object, verifies the challenge, and stores
     * the new credential if valid.
     *
     * @param string $credentialJson The JSON-encoded AuthenticatorAttestationResponse from the client
     * @param string $expectedChallenge The challenge that was sent to the client
     */
    public function verifyRegistration(
        string $credentialJson,
        string $expectedChallenge,
    ): RegistrationResult;

    /**
     * Generate options for an authentication (assertion) ceremony.
     *
     * Creates a new challenge and returns the PublicKeyCredentialRequestOptions
     * to send to the client.
     *
     * @param string|null $userId The user identifier (null for discoverable/passkey flow)
     */
    public function generateAuthenticationOptions(?string $userId = null): AuthenticationOptions;

    /**
     * Verify an authentication (assertion) ceremony response.
     *
     * Validates the assertion, verifies the challenge, checks the signature
     * counter for clone detection, and returns the authenticated credential.
     *
     * @param string $credentialJson The JSON-encoded AuthenticatorAssertionResponse from the client
     * @param string $expectedChallenge The challenge that was sent to the client
     * @param string|null $expectedUserId The expected user (null for discoverable/passkey flow)
     */
    public function verifyAuthentication(
        string $credentialJson,
        string $expectedChallenge,
        ?string $expectedUserId = null,
    ): AuthenticationResult;
}
