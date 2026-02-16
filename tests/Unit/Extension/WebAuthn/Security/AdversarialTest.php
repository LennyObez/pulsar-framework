<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\WebAuthn\Adapter\CborDecoder;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

use function chr;

/**
 * Adversarial security tests for the WebAuthn extension.
 *
 * Each test simulates a specific attack and verifies the implementation
 * correctly prevents it. These tests are written from an attacker's perspective.
 */
#[CoversClass(RegistrationCeremony::class)]
#[CoversClass(AuthenticationCeremony::class)]
#[CoversClass(AttestationVerifier::class)]
#[CoversClass(CborDecoder::class)]
final class AdversarialTest extends TestCase
{
    private WebAuthnConfig $config;
    private CredentialRepositoryInterface&MockObject $credentialRepo;
    private AuditLoggerInterface&MockObject $auditLogger;

    protected function setUp(): void
    {
        $this->config = new WebAuthnConfig(
            rpName: 'Pulsar Test',
            rpId: 'example.com',
            origin: 'https://example.com',
            userVerification: 'required',
            attestation: 'direct',
        );

        $this->credentialRepo = $this->createMock(CredentialRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
    }

    // ========================================================================
    // Attack Vector 1: Challenge Replay
    // ========================================================================

    #[Test]
    public function registrationWithWrongChallengeRejected(): void
    {
        $ceremony = new RegistrationCeremony(
            config: $this->config,
            attestationVerifier: new AttestationVerifier(),
            credentialRepository: $this->credentialRepo,
            auditLogger: $this->auditLogger,
        );

        $options = $ceremony->generateOptions('user-1', 'testuser');

        // Attacker substitutes a different challenge
        $wrongChallenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $wrongChallenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'attestationObject' => rtrim(strtr(base64_encode('fake-attestation'), '+/', '-_'), '='),
            ],
            'rawId' => rtrim(strtr(base64_encode('cred-id-1'), '+/', '-_'), '='),
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('invalid or expired');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    #[Test]
    public function authenticationWithWrongChallengeRejected(): void
    {
        $credentialSource = $this->createCredentialSource('cred-id-1', 'user-1');
        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $wrongChallenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $wrongChallenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x05);
        $counter = pack('N', 10);
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-id-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('invalid or expired');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Attack Vector 2: Origin Spoofing
    // ========================================================================

    #[Test]
    public function registrationWithWrongOriginRejected(): void
    {
        $ceremony = new RegistrationCeremony(
            config: $this->config,
            attestationVerifier: new AttestationVerifier(),
            credentialRepository: $this->credentialRepo,
            auditLogger: $this->auditLogger,
        );

        $options = $ceremony->generateOptions('user-1', 'testuser');

        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options->challenge,
            'origin' => 'https://evil.example.com',
        ], JSON_THROW_ON_ERROR);

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'attestationObject' => rtrim(strtr(base64_encode('fake'), '+/', '-_'), '='),
            ],
            'rawId' => rtrim(strtr(base64_encode('cred-id-1'), '+/', '-_'), '='),
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Origin mismatch');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    #[Test]
    public function authenticationWithWrongOriginRejected(): void
    {
        $credentialSource = $this->createCredentialSource('cred-id-1', 'user-1');
        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://attacker.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x05);
        $counter = pack('N', 10);
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-id-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Origin mismatch');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Attack Vector 3: RP ID Mismatch
    // ========================================================================

    #[Test]
    public function authenticationWithWrongRpIdHashRejected(): void
    {
        $credentialSource = $this->createCredentialSource('cred-id-1', 'user-1');
        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // Build authData with wrong RP ID hash
        $wrongRpIdHash = hash('sha256', 'evil.example.com', true);
        $flags = chr(0x05); // UP + UV
        $counter = pack('N', 10);
        $authData = $wrongRpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-id-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('RP ID hash mismatch');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Attack Vector 4: User Presence and Verification Bypass
    // ========================================================================

    #[Test]
    public function authenticationWithoutUserPresenceFlagRejected(): void
    {
        $credentialSource = $this->createCredentialSource('cred-id-1', 'user-1');
        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // Build authData with correct RP ID hash but no UP flag
        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x04); // UV only, no UP
        $counter = pack('N', 10);
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-id-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User presence flag not set');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    #[Test]
    public function authenticationWithoutUserVerificationFlagRejectedWhenRequired(): void
    {
        $credentialSource = $this->createCredentialSource('cred-id-1', 'user-1');
        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // Build authData with UP flag but no UV flag
        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x01); // UP only, no UV
        $counter = pack('N', 10);
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-id-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User verification required but not performed');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Attack Vector 5: Clone Detection (Counter Regression)
    // ========================================================================

    #[Test]
    public function authenticationWithDecreasedCounterDetectsClone(): void
    {
        // Generate an EC key pair for signature verification
        $keyPair = openssl_pkey_new([
            'ec' => ['curve_name' => 'prime256v1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        self::assertNotFalse($keyPair);
        $keyDetails = openssl_pkey_get_details($keyPair);
        self::assertIsArray($keyDetails);
        /** @var string $publicKeyPem */
        $publicKeyPem = $keyDetails['key'];

        $credentialSource = new CredentialSource(
            credentialId: 'cred-clone-1',
            userId: 'user-1',
            publicKeyPem: $publicKeyPem,
            signatureCounter: 100, // Stored counter is 100
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: str_repeat('0', 32),
            createdAt: new DateTimeImmutable(),
        );

        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // Build authData with counter=50 (regression from stored 100)
        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x05); // UP + UV
        $counter = pack('N', 50); // Counter regression!
        $authData = $rpIdHash . $flags . $counter;

        // Create a valid signature
        $clientDataHash = hash('sha256', $clientData, true);
        $signedData = $authData . $clientDataHash;
        $signature = '';
        openssl_sign($signedData, $signature, $keyPair, OPENSSL_ALGO_SHA256);
        self::assertIsString($signature);

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-clone-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('clone detected');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    #[Test]
    public function authenticationWithSameCounterDetectsClone(): void
    {
        $keyPair = openssl_pkey_new([
            'ec' => ['curve_name' => 'prime256v1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        self::assertNotFalse($keyPair);
        $keyDetails = openssl_pkey_get_details($keyPair);
        self::assertIsArray($keyDetails);
        /** @var string $publicKeyPem */
        $publicKeyPem = $keyDetails['key'];

        $credentialSource = new CredentialSource(
            credentialId: 'cred-clone-2',
            userId: 'user-1',
            publicKeyPem: $publicKeyPem,
            signatureCounter: 100,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: str_repeat('0', 32),
            createdAt: new DateTimeImmutable(),
        );

        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // Counter=100, same as stored (no increase)
        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x05);
        $counter = pack('N', 100);
        $authData = $rpIdHash . $flags . $counter;

        $clientDataHash = hash('sha256', $clientData, true);
        $signedData = $authData . $clientDataHash;
        $signature = '';
        openssl_sign($signedData, $signature, $keyPair, OPENSSL_ALGO_SHA256);
        self::assertIsString($signature);

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-clone-2'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('clone detected');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Attack Vector 6: Credential Cross-User Attack
    // ========================================================================

    #[Test]
    public function credentialBelongingToUserACannotAuthenticateUserB(): void
    {
        $credentialSource = $this->createCredentialSource('cred-cross-1', 'user-a');

        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        // Return user-a's credential when looking up user-b (to allow generateOptions to pass)
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();

        // Attacker tries to authenticate as user-b using user-a's credential
        $options = $ceremony->generateOptions('user-b');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x05);
        $counter = pack('N', 10);
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-cross-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('does not belong to the expected user');
        $ceremony->verify($credentialJson, $options->challenge, 'user-b');
    }

    // ========================================================================
    // Attack Vector 7: Attestation Format Policy Bypass
    // ========================================================================

    #[Test]
    public function disallowedAttestationFormatRejected(): void
    {
        $verifier = new AttestationVerifier(['none']); // Only 'none' allowed

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('not allowed by policy');
        $verifier->verify('packed', 'fake-attestation-object', 'fake-client-data');
    }

    #[Test]
    public function unknownAttestationFormatRejected(): void
    {
        $verifier = new AttestationVerifier(['none', 'packed']);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('not allowed by policy');
        $verifier->verify('android-key', 'fake-attestation-object', 'fake-client-data');
    }

    #[Test]
    public function formatPolicyAllowlistIsStrict(): void
    {
        $verifier = new AttestationVerifier(['none', 'packed']);

        self::assertTrue($verifier->isFormatAllowed('none'));
        self::assertTrue($verifier->isFormatAllowed('packed'));
        self::assertFalse($verifier->isFormatAllowed('android-key'));
        self::assertFalse($verifier->isFormatAllowed('tpm'));
        self::assertFalse($verifier->isFormatAllowed('fido-u2f'));
    }

    // ========================================================================
    // Attack Vector 8: Type Confusion in Client Data
    // ========================================================================

    #[Test]
    public function registrationWithGetTypeRejected(): void
    {
        $ceremony = new RegistrationCeremony(
            config: $this->config,
            attestationVerifier: new AttestationVerifier(),
            credentialRepository: $this->credentialRepo,
            auditLogger: $this->auditLogger,
        );

        $options = $ceremony->generateOptions('user-1', 'testuser');

        // Attacker sends webauthn.get type during registration
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'attestationObject' => rtrim(strtr(base64_encode('fake'), '+/', '-_'), '='),
            ],
            'rawId' => rtrim(strtr(base64_encode('cred-id-1'), '+/', '-_'), '='),
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage("Expected type 'webauthn.create'");
        $ceremony->verify($credentialJson, $options->challenge);
    }

    #[Test]
    public function authenticationWithCreateTypeRejected(): void
    {
        $credentialSource = $this->createCredentialSource('cred-type-1', 'user-1');
        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        // Attacker sends webauthn.create type during authentication
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x05);
        $counter = pack('N', 10);
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-type-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage("Expected type 'webauthn.get'");
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Attack Vector 9: User Handle Mismatch in Discoverable Credential Flow
    // ========================================================================

    #[Test]
    public function userHandleMismatchInDiscoverableFlowRejected(): void
    {
        $keyPair = openssl_pkey_new([
            'ec' => ['curve_name' => 'prime256v1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        self::assertNotFalse($keyPair);
        $keyDetails = openssl_pkey_get_details($keyPair);
        self::assertIsArray($keyDetails);
        /** @var string $publicKeyPem */
        $publicKeyPem = $keyDetails['key'];

        $credentialSource = new CredentialSource(
            credentialId: 'cred-disc-1',
            userId: 'user-a',
            publicKeyPem: $publicKeyPem,
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: str_repeat('0', 32),
            createdAt: new DateTimeImmutable(),
        );

        $this->credentialRepo->method('findById')->willReturn($credentialSource);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions(null); // Discoverable flow

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x05); // UP + UV
        $counter = pack('N', 1);
        $authData = $rpIdHash . $flags . $counter;

        $clientDataHash = hash('sha256', $clientData, true);
        $signedData = $authData . $clientDataHash;
        $signature = '';
        openssl_sign($signedData, $signature, $keyPair, OPENSSL_ALGO_SHA256);
        self::assertIsString($signature);

        // Attacker claims to be user-b via userHandle but credential belongs to user-a
        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-disc-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
                'userHandle' => rtrim(strtr(base64_encode('user-b'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User handle does not match credential owner');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Attack Vector 10: Truncated / Malformed Authenticator Data
    // ========================================================================

    #[Test]
    public function truncatedAuthenticatorDataRejected(): void
    {
        $credentialSource = $this->createCredentialSource('cred-trunc-1', 'user-1');
        $this->credentialRepo->method('findById')->willReturn($credentialSource);
        $this->credentialRepo->method('findByUserId')->willReturn([$credentialSource]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // Only 10 bytes of authData (minimum is 37)
        $truncatedAuthData = str_repeat("\x00", 10);

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-trunc-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($truncatedAuthData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('too short');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Attack Vector 11: CBOR Bomb / Malformed CBOR
    // ========================================================================

    #[Test]
    public function malformedCborDataRejected(): void
    {
        $this->expectException(WebAuthnException::class);
        CborDecoder::decode('');
    }

    #[Test]
    public function truncatedCborDataRejected(): void
    {
        // Major type 2 (byte string) with length 255, but only 3 bytes of data
        $truncated = chr(0x58) . chr(0xFF) . 'abc';

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('unexpected end of data');
        CborDecoder::decode($truncated);
    }

    // ========================================================================
    // Attack Vector 12: Challenge Entropy Verification
    // ========================================================================

    #[Test]
    public function registrationChallengesAreUnique(): void
    {
        $ceremony = new RegistrationCeremony(
            config: $this->config,
            attestationVerifier: new AttestationVerifier(),
            credentialRepository: $this->credentialRepo,
            auditLogger: $this->auditLogger,
        );

        $challenges = [];
        for ($i = 0; $i < 100; $i++) {
            $options = $ceremony->generateOptions('user-1', 'testuser');
            $challenges[] = $options->challenge;
        }

        // All challenges should be unique (collision probability negligible at 32 bytes)
        self::assertCount(100, array_unique($challenges));
    }

    #[Test]
    public function authenticationChallengesAreUnique(): void
    {
        $this->credentialRepo->method('findByUserId')->willReturn([
            $this->createCredentialSource('cred-1', 'user-1'),
        ]);

        $ceremony = $this->createAuthenticationCeremony();

        $challenges = [];
        for ($i = 0; $i < 100; $i++) {
            $options = $ceremony->generateOptions('user-1');
            $challenges[] = $options->challenge;
        }

        self::assertCount(100, array_unique($challenges));
    }

    // ========================================================================
    // Attack Vector 13: Unregistered Credential
    // ========================================================================

    #[Test]
    public function authenticationWithUnregisteredCredentialRejected(): void
    {
        $this->credentialRepo->method('findById')->willReturn(null);
        $this->credentialRepo->method('findByUserId')->willReturn([
            $this->createCredentialSource('real-cred', 'user-1'),
        ]);

        $ceremony = $this->createAuthenticationCeremony();
        $options = $ceremony->generateOptions('user-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options->challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x05);
        $counter = pack('N', 1);
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('unknown-credential-id'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientData), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode('fake-sig'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('not registered');
        $ceremony->verify($credentialJson, $options->challenge);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createAuthenticationCeremony(): AuthenticationCeremony
    {
        return new AuthenticationCeremony(
            config: $this->config,
            credentialRepository: $this->credentialRepo,
            auditLogger: $this->auditLogger,
        );
    }

    private function createCredentialSource(string $credentialId, string $userId): CredentialSource
    {
        return new CredentialSource(
            credentialId: $credentialId,
            userId: $userId,
            publicKeyPem: "-----BEGIN PUBLIC KEY-----\nfake\n-----END PUBLIC KEY-----\n",
            signatureCounter: 5,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: str_repeat('0', 32),
            createdAt: new DateTimeImmutable(),
        );
    }
}
