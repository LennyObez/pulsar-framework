<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Adapter;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationOptions;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationResult;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationOptions;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationResult;
use Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface;

/**
 * WebAuthn server implementation orchestrating registration and authentication ceremonies.
 *
 * Delegates to RegistrationCeremony and AuthenticationCeremony for the actual
 * WebAuthn protocol logic. This class provides the top-level API that matches
 * the WebAuthnServerInterface contract.
 */
#[Internal(reason: 'WebAuthn adapter implementation')]
final readonly class WebAuthnServer implements WebAuthnServerInterface
{
    public function __construct(
        private RegistrationCeremony $registrationCeremony,
        private AuthenticationCeremony $authenticationCeremony,
    ) {}

    public function generateRegistrationOptions(
        string $userId,
        string $userName,
        array $excludeCredentialIds = [],
    ): RegistrationOptions {
        return $this->registrationCeremony->generateOptions($userId, $userName, $excludeCredentialIds);
    }

    public function verifyRegistration(
        string $credentialJson,
        string $expectedChallenge,
    ): RegistrationResult {
        return $this->registrationCeremony->verify($credentialJson, $expectedChallenge);
    }

    public function generateAuthenticationOptions(?string $userId = null): AuthenticationOptions
    {
        return $this->authenticationCeremony->generateOptions($userId);
    }

    /**
     * `$expectedUserId` has no default — see the interface docblock for
     * the rationale (a default would allow silent "any user"
     * authentication through the discoverable-credentials path).
     */
    public function verifyAuthentication(
        string $credentialJson,
        string $expectedChallenge,
        ?string $expectedUserId,
    ): AuthenticationResult {
        return $this->authenticationCeremony->verify($credentialJson, $expectedChallenge, $expectedUserId);
    }
}
