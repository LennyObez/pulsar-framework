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
     * F385.16: `$expectedUserId` is REQUIRED — the parameter has no default
     * value so the caller must explicitly pass either a user identifier
     * (the typical username+password+passkey flow) or `null` (the
     * discoverable-credentials / resident-keys flow where the
     * authenticator selects the credential and identity is derived from
     * the assertion). The previous signature defaulted to `null` and
     * silently authenticated "any user" — a subtle account-confusion
     * vector if downstream code did not also check the resolved user
     * matched the calling session.
     *
     * @param string $credentialJson The JSON-encoded AuthenticatorAssertionResponse from the client
     * @param string $expectedChallenge The challenge that was sent to the client
     * @param string|null $expectedUserId The expected user identifier, or
     *                                    `null` to accept the user the
     *                                    authenticator self-asserts via
     *                                    discoverable credentials.
     */
    public function verifyAuthentication(
        string $credentialJson,
        string $expectedChallenge,
        ?string $expectedUserId,
    ): AuthenticationResult;
}
