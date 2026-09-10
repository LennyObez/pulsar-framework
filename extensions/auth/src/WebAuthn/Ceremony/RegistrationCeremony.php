<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Ceremony;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\Auth\WebAuthn\Adapter\CborDecoder;
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryChallengeStore;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\ChallengeStoreInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function is_array;
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
    private ChallengeStoreInterface $challengeStore;

    /**
     * @param ClockInterface|null $clock Injected so the challenge window is
     *        testable; the system clock is used when none is given.
     * @param ChallengeStoreInterface|null $challengeStore Records which challenges
     *        have been answered, so none is answered twice. Defaults to a
     *        process-local store sharing this ceremony's clock; a deployment
     *        running more than one worker MUST bind a shared implementation, or
     *        a replay routed to another worker finds an empty store. Last in the
     *        list so the parameter could be added without renumbering the
     *        positional arguments every existing caller passes.
     */
    public function __construct(
        private WebAuthnConfig $config,
        private AttestationVerifierInterface $attestationVerifier,
        private CredentialRepositoryInterface $credentialRepository,
        private AuditLoggerInterface $auditLogger,
        private ?ClockInterface $clock = null,
        ?ChallengeStoreInterface $challengeStore = null,
    ) {
        $this->challengeStore = $challengeStore ?? new InMemoryChallengeStore($clock);
    }

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
        // The challenge carries its own issuance instant, so `verify()` enforces
        // `challenge_ttl_seconds` without having to look the challenge up. The
        // store consulted at verification time records only whether a challenge
        // has been ANSWERED; nothing is written here, because a challenge that is
        // issued and never used costs nothing and needs no record.
        $challengeB64 = Challenge::issue($this->now());

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
     * @param string $userId Authenticated user ID from the server session (never from client JSON)
     */
    public function verify(string $credentialJson, string $expectedChallenge, string $userId = ''): RegistrationResult
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
            $this->consumeChallenge($expectedChallenge);

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

            $credentialSource = $this->extractCredentialSource($authData, $credential, $format, $attestationResult->aaguid ?? '', $userId);

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
                actor: $userId !== '' ? AuditActor::user($userId) : AuditActor::anonymous(),
                action: 'webauthn.registration.failed',
                resource: 'webauthn:registration',
                metadata: ['error' => $e->getMessage(), 'error_code' => $e->errorCode()],
            );

            throw $e;
        }
    }

    /**
     * Claim the challenge, refusing a ceremony that answers one already answered.
     *
     * `challenge_ttl_seconds` bounds the replay window; this closes it. Until
     * this call existed, nothing marked a challenge spent, so a captured response
     * stayed usable for the whole of its TTL — five minutes by default — against
     * a relying party that {@see \Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface}
     * documents as issuing "one-time challenges".
     *
     * The claim is made BEFORE the cryptographic checks that follow, so a
     * challenge is spent by the first response that answers it, whether or not
     * that response turns out to verify. Consuming only on success would leave a
     * captured challenge open to unlimited attempts, which is the property the
     * single-use rule exists to remove.
     */
    private function consumeChallenge(string $challenge): void
    {
        $expiresAt = Challenge::expiresAt($challenge, $this->config->challengeTtlSeconds);

        if ($expiresAt === null) {
            // The freshness gate above already refuses a value this server did
            // not mint, so this is unreachable today. It fails closed rather than
            // skipping the claim, because a reordering that moved the gate would
            // otherwise turn single-use off in silence.
            throw WebAuthnException::expiredChallenge();
        }

        if (!$this->challengeStore->consume($challenge, $expiresAt)) {
            throw WebAuthnException::replayedChallenge();
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

        // Freshness is read from the relying party's own copy of the challenge,
        // never from the client echo: the two are byte-identical at this point,
        // and only the server copy is trustworthy as a source of the instant.
        if (!Challenge::isFresh($expectedChallenge, $this->config->challengeTtlSeconds, $this->now())) {
            throw WebAuthnException::expiredChallenge();
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
     * @param string $serverUserId User ID from the server session
     */
    private function extractCredentialSource(
        string $authData,
        array $credential,
        string $format,
        string $aaguid,
        string $serverUserId,
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

        // Extract the COSE algorithm ID from the key (key 3 in the COSE_Key map)
        /** @var array<int, mixed> $coseKeyMap */
        $coseKeyMap = CborDecoder::decode($coseKeyBytes);
        /** @var int $algorithmId */
        $algorithmId = $coseKeyMap[3] ?? -7; // Default ES256

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

        // Use the server-provided userId (from the authenticated session),
        // never from client-controlled JSON to prevent user ID spoofing.
        $userId = $serverUserId;

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
            algorithmId: $algorithmId,
        );
    }

    /**
     * The current instant, from the injected clock when one is available.
     */
    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
