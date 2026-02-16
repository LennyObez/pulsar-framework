<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Exception;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * WebAuthn ceremony and verification exceptions.
 */
#[Api(since: '1.0.0')]
final class WebAuthnException extends RuntimeException
{
    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidChallenge(): self
    {
        return new self('invalid_challenge', 'The challenge response is invalid or expired');
    }

    public static function invalidAttestation(string $detail): self
    {
        return new self('invalid_attestation', "Attestation verification failed: {$detail}");
    }

    public static function invalidAssertion(string $detail): self
    {
        return new self('invalid_assertion', "Assertion verification failed: {$detail}");
    }

    public static function disallowedFormat(string $format): self
    {
        return new self('disallowed_format', "Attestation format '{$format}' is not allowed by policy");
    }

    public static function cloneDetected(string $credentialId): self
    {
        return new self('clone_detected', 'Authenticator clone detected for credential: signature counter did not increase');
    }

    public static function credentialNotFound(string $credentialId): self
    {
        return new self('credential_not_found', 'The credential is not registered');
    }

    public static function userNotFound(): self
    {
        return new self('user_not_found', 'No credentials found for the user');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
