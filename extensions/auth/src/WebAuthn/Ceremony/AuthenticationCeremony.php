<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Ceremony;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function ord;
use function strlen;

/**
 * WebAuthn authentication (assertion) ceremony implementation.
 *
 * Generates PublicKeyCredentialRequestOptions and verifies assertion responses
 * for authenticating users with registered WebAuthn credentials.
 * Supports both username-based and discoverable (passkey) flows.
 */
#[Internal(reason: 'WebAuthn ceremony implementation')]
final readonly class AuthenticationCeremony
{
    public function __construct(
        private WebAuthnConfig $config,
        private CredentialRepositoryInterface $credentialRepository,
        private AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Generate authentication options (PublicKeyCredentialRequestOptions).
     *
     * @param string|null $userId User identifier (null for passkey/discoverable flow)
     */
    public function generateOptions(?string $userId = null): AuthenticationOptions
    {
        $challenge = random_bytes(32);
        $challengeB64 = $this->base64UrlEncode($challenge);

        $this->auditLogger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: $userId,
            action: 'webauthn.authentication.started',
            resource: 'webauthn:authentication',
            metadata: ['user_id' => $userId, 'discoverable' => $userId === null],
        );

        $allowCredentials = [];

        if ($userId !== null) {
            $credentials = $this->credentialRepository->findByUserId($userId);

            if ($credentials === []) {
                throw WebAuthnException::userNotFound();
            }

            foreach ($credentials as $credential) {
                $allowCredentials[] = [
                    'type' => 'public-key',
                    'id' => $this->base64UrlEncode($credential->credentialId),
                    'transports' => $credential->transports,
                ];
            }
        }

        $publicKeyOptions = [
            'challenge' => $challengeB64,
            'timeout' => $this->config->timeout,
            'rpId' => $this->config->rpId,
            'userVerification' => $this->config->userVerification,
        ];

        if ($allowCredentials !== []) {
            $publicKeyOptions['allowCredentials'] = $allowCredentials;
        }

        return new AuthenticationOptions(
            challenge: $challengeB64,
            publicKeyOptions: $publicKeyOptions,
        );
    }

    /**
     * Verify an authentication (assertion) response from the client.
     *
     * @param string $credentialJson JSON-encoded AuthenticatorAssertionResponse
     * @param string $expectedChallenge The base64url-encoded challenge that was sent
     * @param string|null $expectedUserId Expected user ID (null for passkey/discoverable flow)
     */
    public function verify(
        string $credentialJson,
        string $expectedChallenge,
        ?string $expectedUserId = null,
    ): AuthenticationResult {
        try {
            /** @var array<string, mixed> $credential */
            $credential = json_decode($credentialJson, true, 32, JSON_THROW_ON_ERROR);

            /** @var string $rawIdB64 */
            $rawIdB64 = $credential['rawId'] ?? $credential['id'] ?? '';
            $rawId = $this->base64UrlDecode($rawIdB64);

            $credentialSource = $this->credentialRepository->findById($rawId);

            if ($credentialSource === null) {
                throw WebAuthnException::credentialNotFound($rawIdB64);
            }

            if ($expectedUserId !== null && !hash_equals($expectedUserId, $credentialSource->userId)) {
                throw WebAuthnException::invalidAssertion('Credential does not belong to the expected user');
            }

            /** @var array<string, mixed> $response */
            $response = $credential['response'] ?? [];

            // Verify client data
            /** @var string $clientDataJsonB64 */
            $clientDataJsonB64 = $response['clientDataJSON'] ?? '';
            $clientDataJson = $this->base64UrlDecode($clientDataJsonB64);
            $this->verifyClientData($clientDataJson, $expectedChallenge);

            // Verify authenticator data
            /** @var string $authDataB64 */
            $authDataB64 = $response['authenticatorData'] ?? '';
            $authData = $this->base64UrlDecode($authDataB64);
            $this->verifyAuthenticatorData($authData);

            // Extract signature counter from authenticator data
            /** @var array{counter: int} $counterData */
            $counterData = unpack('Ncounter', $authData, 33);
            $newCounter = $counterData['counter'];

            // Clone detection: counter must increase (unless both are 0, which means counter is unsupported)
            if ($credentialSource->signatureCounter > 0 || $newCounter > 0) {
                if ($newCounter <= $credentialSource->signatureCounter) {
                    $this->auditLogger->log(
                        event: AuditEvent::SecurityEvent,
                        outcome: AuditOutcome::Failure,
                        actor: $credentialSource->userId,
                        action: 'webauthn.clone_detected',
                        resource: 'webauthn:credential:' . $this->base64UrlEncode($credentialSource->credentialId),
                        metadata: [
                            'credential_id' => $this->base64UrlEncode($credentialSource->credentialId),
                            'stored_counter' => $credentialSource->signatureCounter,
                            'received_counter' => $newCounter,
                        ],
                    );

                    throw WebAuthnException::cloneDetected($this->base64UrlEncode($credentialSource->credentialId));
                }
            }

            // Verify signature
            /** @var string $signatureB64 */
            $signatureB64 = $response['signature'] ?? '';
            $signature = $this->base64UrlDecode($signatureB64);
            $this->verifySignature($authData, $clientDataJson, $signature, $credentialSource);

            // Update the counter
            $this->credentialRepository->updateCounter($credentialSource->credentialId, $newCounter);

            // Check user verification flag
            $flags = ord($authData[32]);
            $userVerified = ($flags & 0x04) !== 0;

            // For discoverable credential flow, extract user ID from userHandle
            $userId = $credentialSource->userId;

            if ($expectedUserId === null) {
                /** @var string $userHandleB64 */
                $userHandleB64 = $response['userHandle'] ?? '';

                if ($userHandleB64 !== '') {
                    $userHandle = $this->base64UrlDecode($userHandleB64);

                    if ($userHandle !== '' && !hash_equals($credentialSource->userId, $userHandle)) {
                        throw WebAuthnException::invalidAssertion('User handle does not match credential owner');
                    }
                }
            }

            $this->auditLogger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Success,
                actor: $userId,
                action: 'webauthn.authentication.verified',
                resource: 'webauthn:credential:' . $this->base64UrlEncode($credentialSource->credentialId),
                metadata: [
                    'credential_id' => $this->base64UrlEncode($credentialSource->credentialId),
                    'user_verified' => $userVerified,
                    'counter' => $newCounter,
                ],
            );

            return new AuthenticationResult(
                credentialId: $credentialSource->credentialId,
                userId: $userId,
                signatureCounter: $newCounter,
                userVerified: $userVerified,
            );
        } catch (WebAuthnException $e) {
            $this->auditLogger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Failure,
                actor: $expectedUserId,
                action: 'webauthn.authentication.failed',
                resource: 'webauthn:authentication',
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
            throw WebAuthnException::invalidAssertion('Invalid client data JSON');
        }

        /** @var string $type */
        $type = $clientData['type'] ?? '';

        if ($type !== 'webauthn.get') {
            throw WebAuthnException::invalidAssertion("Expected type 'webauthn.get', got '$type'");
        }

        /** @var string $challenge */
        $challenge = $clientData['challenge'] ?? '';

        if (!hash_equals($expectedChallenge, $challenge)) {
            throw WebAuthnException::invalidChallenge();
        }

        /** @var string $origin */
        $origin = $clientData['origin'] ?? '';

        if ($origin !== $this->config->origin) {
            throw WebAuthnException::invalidAssertion("Origin mismatch: expected '{$this->config->origin}', got '$origin'");
        }
    }

    /**
     * Verify authenticator data flags (user presence, RP ID hash).
     */
    private function verifyAuthenticatorData(string $authData): void
    {
        if (strlen($authData) < 37) {
            throw WebAuthnException::invalidAssertion('Authenticator data too short');
        }

        $rpIdHash = substr($authData, 0, 32);
        $expectedRpIdHash = hash('sha256', $this->config->rpId, true);

        if (!hash_equals($expectedRpIdHash, $rpIdHash)) {
            throw WebAuthnException::invalidAssertion('RP ID hash mismatch');
        }

        $flags = ord($authData[32]);
        $userPresent = ($flags & 0x01) !== 0;

        if (!$userPresent) {
            throw WebAuthnException::invalidAssertion('User presence flag not set');
        }

        if ($this->config->userVerification === 'required') {
            $userVerified = ($flags & 0x04) !== 0;

            if (!$userVerified) {
                throw WebAuthnException::invalidAssertion('User verification required but not performed');
            }
        }
    }

    /**
     * Verify the assertion signature using the stored credential public key.
     *
     * Uses the credential's COSE algorithm ID to select the correct OpenSSL
     * hash algorithm, rather than assuming SHA-256 for all key types.
     */
    private function verifySignature(
        string $authData,
        string $clientDataJson,
        string $signature,
        CredentialSource $credentialSource,
    ): void {
        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authData . $clientDataHash;

        $publicKey = openssl_pkey_get_public($credentialSource->publicKeyPem);

        if ($publicKey === false) {
            throw WebAuthnException::invalidAssertion('Cannot load credential public key');
        }

        $algorithm = AttestationVerifier::coseAlgToOpenSsl($credentialSource->algorithmId);

        $valid = openssl_verify($signedData, $signature, $publicKey, $algorithm);

        if ($valid !== 1) {
            throw WebAuthnException::invalidAssertion('Signature verification failed');
        }
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
