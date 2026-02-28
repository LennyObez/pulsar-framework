<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Ceremony;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\Auth\WebAuthn\Adapter\CborDecoder;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function is_array;
use function is_string;
use function ord;
use function strlen;

/**
 * WebAuthn registration (attestation) ceremony implementation.
 *
 * Generates PublicKeyCredentialCreationOptions and verifies attestation responses
 * to register new WebAuthn credentials for users.
 */
#[Internal(reason: 'WebAuthn ceremony implementation')]
final readonly class RegistrationCeremony
{
    public function __construct(
        private WebAuthnConfig $config,
        private AttestationVerifierInterface $attestationVerifier,
        private CredentialRepositoryInterface $credentialRepository,
        private AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Generate registration options (PublicKeyCredentialCreationOptions).
     *
     * @param string $userId Internal user identifier
     * @param string $userName User display name
     * @param list<string> $excludeCredentialIds Already-registered credential IDs to exclude
     */
    public function generateOptions(
        string $userId,
        string $userName,
        array $excludeCredentialIds = [],
    ): RegistrationOptions {
        $challenge = random_bytes(32);
        $challengeB64 = $this->base64UrlEncode($challenge);

        $this->auditLogger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: $userId,
            action: 'webauthn.registration.started',
            resource: 'webauthn:registration',
            metadata: ['user_id' => $userId],
        );

        $excludeCredentials = [];

        foreach ($excludeCredentialIds as $credId) {
            $credential = $this->credentialRepository->findById($credId);
            $excludeCredentials[] = [
                'type' => 'public-key',
                'id' => $this->base64UrlEncode($credId),
                'transports' => $credential->transports ?? [],
            ];
        }

        $publicKeyOptions = [
            'rp' => [
                'name' => $this->config->rpName,
                'id' => $this->config->rpId,
            ],
            'user' => [
                'id' => $this->base64UrlEncode($userId),
                'name' => $userName,
                'displayName' => $userName,
            ],
            'challenge' => $challengeB64,
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257],  // RS256
            ],
            'timeout' => $this->config->timeout,
            'excludeCredentials' => $excludeCredentials,
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'requireResidentKey' => false,
                'userVerification' => $this->config->userVerification,
            ],
            'attestation' => $this->config->attestation,
        ];

        return new RegistrationOptions(
            challenge: $challengeB64,
            publicKeyOptions: $publicKeyOptions,
        );
    }

    /**
     * Verify a registration (attestation) response from the client.
     *
     * @param string $credentialJson JSON-encoded AuthenticatorAttestationResponse
     * @param string $expectedChallenge The base64url-encoded challenge that was sent
     */
    public function verify(string $credentialJson, string $expectedChallenge): RegistrationResult
    {
        try {
            /** @var array<string, mixed> $credential */
            $credential = json_decode($credentialJson, true, 32, JSON_THROW_ON_ERROR);

            /** @var array<string, mixed> $response */
            $response = $credential['response'] ?? [];

            /** @var string $clientDataJsonB64 */
            $clientDataJsonB64 = $response['clientDataJSON'] ?? '';
            $clientDataJson = $this->base64UrlDecode($clientDataJsonB64);

            $this->verifyClientData($clientDataJson, $expectedChallenge);

            /** @var string $attestationObjectB64 */
            $attestationObjectB64 = $response['attestationObject'] ?? '';
            $attestationObject = $this->base64UrlDecode($attestationObjectB64);

            /** @var array<string|int, mixed> $attObj */
            $attObj = CborDecoder::decode($attestationObject);
            /** @var string $format */
            $format = $attObj['fmt'] ?? 'none';
            /** @var string $authData */
            $authData = $attObj['authData'] ?? '';

            $this->verifyAuthenticatorData($authData);

            $attestationResult = $this->attestationVerifier->verify($format, $attestationObject, $clientDataJson);

            $credentialSource = $this->extractCredentialSource($authData, $credential, $format, $attestationResult->aaguid ?? '');

            $this->credentialRepository->persist($credentialSource);

            $this->auditLogger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Success,
                actor: $credentialSource->userId,
                action: 'webauthn.registration.verified',
                resource: 'webauthn:credential:' . $this->base64UrlEncode($credentialSource->credentialId),
                metadata: [
                    'credential_id' => $this->base64UrlEncode($credentialSource->credentialId),
                    'attestation_format' => $format,
                    'discoverable' => $credentialSource->discoverable,
                ],
            );

            return new RegistrationResult(
                credential: $credentialSource,
                attestationFormat: $format,
                isDiscoverable: $credentialSource->discoverable,
            );
        } catch (WebAuthnException $e) {
            $this->auditLogger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Failure,
                actor: AuditActor::anonymous(),
                action: 'webauthn.registration.failed',
                resource: 'webauthn:registration',
                metadata: ['error' => $e->getMessage(), 'error_code' => $e->errorCode()],
            );

            throw $e;
        }
    }

    /**
     * Verify the client data JSON (type, challenge, origin).
     */
    private function verifyClientData(string $clientDataJson, string $expectedChallenge): void
    {
        /** @var array<string, mixed>|null $clientData */
        $clientData = json_decode($clientDataJson, true, 16);

        if ($clientData === null) {
            throw WebAuthnException::invalidAttestation('Invalid client data JSON');
        }

        /** @var string $type */
        $type = $clientData['type'] ?? '';

        if ($type !== 'webauthn.create') {
            throw WebAuthnException::invalidAttestation("Expected type 'webauthn.create', got '$type'");
        }

        /** @var string $challenge */
        $challenge = $clientData['challenge'] ?? '';

        if (!hash_equals($expectedChallenge, $challenge)) {
            throw WebAuthnException::invalidChallenge();
        }

        /** @var string $origin */
        $origin = $clientData['origin'] ?? '';

        if ($origin !== $this->config->origin) {
            throw WebAuthnException::invalidAttestation("Origin mismatch: expected '{$this->config->origin}', got '$origin'");
        }
    }

    /**
     * Verify the authenticator data flags.
     */
    private function verifyAuthenticatorData(string $authData): void
    {
        if (strlen($authData) < 37) {
            throw WebAuthnException::invalidAttestation('Authenticator data too short');
        }

        $rpIdHash = substr($authData, 0, 32);
        $expectedRpIdHash = hash('sha256', $this->config->rpId, true);

        if (!hash_equals($expectedRpIdHash, $rpIdHash)) {
            throw WebAuthnException::invalidAttestation('RP ID hash mismatch');
        }

        $flags = ord($authData[32]);
        $userPresent = ($flags & 0x01) !== 0;

        if (!$userPresent) {
            throw WebAuthnException::invalidAttestation('User presence flag not set');
        }

        if ($this->config->userVerification === 'required') {
            $userVerified = ($flags & 0x04) !== 0;

            if (!$userVerified) {
                throw WebAuthnException::invalidAttestation('User verification required but not performed');
            }
        }

        $hasAttestedCredentialData = ($flags & 0x40) !== 0;

        if (!$hasAttestedCredentialData) {
            throw WebAuthnException::invalidAttestation('Attested credential data flag not set');
        }
    }

    /**
     * Extract the credential source from authenticator data.
     *
     * @param array<string, mixed> $credential The parsed credential JSON
     */
    private function extractCredentialSource(
        string $authData,
        array $credential,
        string $format,
        string $aaguid,
    ): CredentialSource {
        // Parse counter from authData (bytes 33-36, big-endian uint32)
        /** @var array{counter: int} $counterData */
        $counterData = unpack('Ncounter', $authData, 33);
        $counter = $counterData['counter'];

        // Parse credential ID length (bytes 53-54)
        /** @var array{len: int} $credIdLenData */
        $credIdLenData = unpack('nlen', $authData, 53);
        $credIdLen = $credIdLenData['len'];

        // Credential ID (bytes 55 to 55+credIdLen)
        $credentialId = substr($authData, 55, $credIdLen);

        // COSE key starts after credential ID
        $coseKeyOffset = 55 + $credIdLen;
        $coseKeyBytes = substr($authData, $coseKeyOffset);

        $publicKeyPem = AttestationVerifier::coseKeyBytesToPem($coseKeyBytes);

        if ($publicKeyPem === null) {
            throw WebAuthnException::invalidAttestation('Cannot extract public key from credential');
        }

        // Extract transports from the credential JSON if provided
        /** @var array<string, mixed> $credResponse */
        $credResponse = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        /** @var list<string> $transports */
        $transports = $credResponse['transports'] ?? [];

        // Determine discoverability from authenticator selection or resident key flag
        $flags = ord($authData[32]);
        $discoverable = ($flags & 0x01) !== 0; // Best guess: full discoverability is client-reported

        /** @var string $rawId */
        $rawId = $credential['rawId'] ?? '';
        $decodedRawId = $this->base64UrlDecode($rawId);

        // Use rawId from credential if available, otherwise use the one from authData
        $finalCredentialId = $decodedRawId !== '' ? $decodedRawId : $credentialId;

        // Extract user ID from the stored state (the credential JSON doesn't contain it for security)
        // The user ID is encoded in the options we sent; we need it from context
        /** @var string $userId */
        $userId = $credential['userId'] ?? '';

        if ($userId === '') {
            // Decode from the user handle in the registration options
            $userIdB64 = $credResponse['userHandle'] ?? '';

            if (is_string($userIdB64) && $userIdB64 !== '') {
                $userId = $this->base64UrlDecode($userIdB64);
            }
        }

        if ($aaguid === '') {
            $aaguid = AttestationVerifier::aaguidFromAuthData($authData);
        }

        return new CredentialSource(
            credentialId: $finalCredentialId,
            userId: $userId,
            publicKeyPem: $publicKeyPem,
            signatureCounter: $counter,
            attestationFormat: $format,
            transports: $transports,
            discoverable: $discoverable,
            aaguid: $aaguid,
            createdAt: new DateTimeImmutable(),
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);

        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded !== false ? $decoded : '';
    }
}
