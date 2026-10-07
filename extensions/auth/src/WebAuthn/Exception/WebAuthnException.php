<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Exception;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * WebAuthn ceremony and verification exceptions.
 * @api
 */
#[Api(since: '1.0.0')]
final class WebAuthnException extends RuntimeException
{
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly string $credentialId = '',
    ) {
        parent::__construct($message);
    }

    public static function invalidChallenge(): self
    {
        return new self('invalid_challenge', 'The challenge response is invalid or expired');
    }

    /**
     * The challenge matched the one the relying party stored, but it is no
     * longer inside `challenge_ttl_seconds` — or it is not a value this server
     * minted at all, so its issuance instant cannot be established.
     *
     * Kept distinct from {@see self::invalidChallenge()} so the audit trail can
     * separate "the response answered a different challenge" (a substitution
     * attempt) from "the response answered the right challenge too late"
     * (an expiry, or a replay of a captured ceremony).
     */
    public static function expiredChallenge(): self
    {
        return new self(
            'expired_challenge',
            'The challenge is outside its validity window or was not issued by this server',
        );
    }

    /**
     * The challenge matched, was inside its window, and had already been answered.
     *
     * Distinct from {@see self::expiredChallenge()} on purpose, and the
     * distinction is the whole finding this code was added for: an expiry says
     * the window closed, whereas this says the window was still open and the
     * ceremony was replayed inside it. The two need different responses — an
     * expiry is what a user who walked away from the prompt produces, while a
     * second answer to a challenge that was already answered is somebody
     * resubmitting a captured ceremony, and an audit trail that renders them as
     * one code cannot tell a slow user from an attacker.
     */
    public static function replayedChallenge(): self
    {
        return new self(
            'replayed_challenge',
            'The challenge has already been used; each challenge is answered once',
        );
    }

    public static function invalidAttestation(string $detail): self
    {
        return new self('invalid_attestation', "Attestation verification failed: $detail");
    }

    public static function invalidAssertion(string $detail): self
    {
        return new self('invalid_assertion', "Assertion verification failed: $detail");
    }

    public static function disallowedFormat(string $format): self
    {
        return new self('disallowed_format', "Attestation format '$format' is not allowed by policy");
    }

    public static function cloneDetected(string $credentialId): self
    {
        // The credential id is retained for structured logging via
        // credentialId() but kept out of the message to avoid leaking
        // identifiers into surfaced error text.
        return new self(
            'clone_detected',
            'Authenticator clone detected for credential: signature counter did not increase',
            $credentialId,
        );
    }

    public static function credentialNotFound(string $credentialId): self
    {
        return new self('credential_not_found', 'The credential is not registered', $credentialId);
    }

    public static function userNotFound(): self
    {
        return new self('user_not_found', 'No credentials found for the user');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The credential id associated with the failure, when applicable, for
     * structured logging. Empty for failures not tied to a specific credential.
     */
    public function credentialId(): string
    {
        return $this->credentialId;
    }
}
