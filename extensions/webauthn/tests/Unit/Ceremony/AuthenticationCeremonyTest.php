<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Ceremony;

use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Audit\AuditEntry;

final class AuthenticationCeremonyTest extends TestCase
{
    private WebAuthnConfig $config;
    private InMemoryCredentialRepository $credentialRepo;
    private AuditLoggerInterface&Stub $auditLogger;

    protected function setUp(): void
    {
        $this->config = new WebAuthnConfig(
            rpName: 'Test RP',
            rpId: 'example.com',
            origin: 'https://example.com',
            userVerification: 'preferred',
        );

        $this->credentialRepo = new InMemoryCredentialRepository();
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));
    }

    private function createCeremony(?WebAuthnConfig $config = null): AuthenticationCeremony
    {
        return new AuthenticationCeremony(
            $config ?? $this->config,
            $this->credentialRepo,
            $this->auditLogger,
        );
    }

    private function makeCredential(
        string $id = 'cred-1',
        int $counter = 0,
        int $algorithmId = -7,
    ): CredentialSource {
        return new CredentialSource(
            credentialId: $id,
            userId: 'user-1',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
            signatureCounter: $counter,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
            algorithmId: $algorithmId,
        );
    }

    #[Test]
    public function generateOptionsWithUserIdReturnsAllowCredentials(): void
    {
        $this->credentialRepo->persist($this->makeCredential());
        $this->credentialRepo->persist($this->makeCredential('cred-2'));

        $ceremony = $this->createCeremony();
        $options = $ceremony->generateOptions('user-1');

        self::assertNotEmpty($options->challenge);
        /** @var array<string, mixed> $pko */
        $pko = $options->publicKeyOptions;
        self::assertSame('example.com', $pko['rpId']);
        self::assertSame('preferred', $pko['userVerification']);
        self::assertSame(60000, $pko['timeout']);
        /** @var list<array<string, mixed>> $allowCredentials */
        $allowCredentials = $pko['allowCredentials'];
        self::assertCount(2, $allowCredentials);
        self::assertSame('public-key', $allowCredentials[0]['type']);
    }

    #[Test]
    public function generateOptionsWithNullUserIdOmitsAllowCredentials(): void
    {
        $ceremony = $this->createCeremony();
        $options = $ceremony->generateOptions();

        self::assertNotEmpty($options->challenge);
        self::assertArrayNotHasKey('allowCredentials', $options->publicKeyOptions);
    }

    #[Test]
    public function generateOptionsThrowsWhenUserHasNoCredentials(): void
    {
        $ceremony = $this->createCeremony();

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('No credentials found for the user');
        $ceremony->generateOptions('nonexistent-user');
    }

    #[Test]
    public function verifyThrowsOnInvalidJson(): void
    {
        $ceremony = $this->createCeremony();

        $this->expectException(JsonException::class);
        $ceremony->verify('not-json', 'challenge');
    }

    #[Test]
    public function verifyThrowsWhenCredentialNotFound(): void
    {
        $ceremony = $this->createCeremony();

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('unknown-cred'), '+/', '-_'), '='),
            'response' => [],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('not registered');
        $ceremony->verify($credentialJson, 'challenge');
    }

    #[Test]
    public function verifyThrowsWhenCredentialBelongsToDifferentUser(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('does not belong to the expected user');
        $ceremony->verify($credentialJson, 'challenge', 'user-2');
    }

    #[Test]
    public function verifyThrowsOnInvalidClientDataJson(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode('not-json'), '+/', '-_'), '='),
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Invalid client data JSON');
        $ceremony->verify($credentialJson, 'challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnWrongClientDataType(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.create', // wrong: should be webauthn.get
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '='),
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage("Expected type 'webauthn.get'");
        $ceremony->verify($credentialJson, 'test-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnChallengeMismatch(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'wrong-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '='),
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('invalid or expired');
        $ceremony->verify($credentialJson, 'correct-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnOriginMismatch(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'test-challenge',
            'origin' => 'https://evil.com',
        ], JSON_THROW_ON_ERROR);

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '='),
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Origin mismatch');
        $ceremony->verify($credentialJson, 'test-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnShortAuthenticatorData(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $shortAuthData = str_repeat("\x00", 10);

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($shortAuthData), '+/', '-_'), '='),
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Authenticator data too short');
        $ceremony->verify($credentialJson, 'test-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnRpIdHashMismatch(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $wrongRpIdHash = hash('sha256', 'wrong.com', true);
        $flags = "\x01"; // UP flag
        $counter = "\x00\x00\x00\x00";
        $authData = $wrongRpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('RP ID hash mismatch');
        $ceremony->verify($credentialJson, 'test-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsWhenUserPresenceNotSet(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x00"; // No flags set
        $counter = "\x00\x00\x00\x00";
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User presence flag not set');
        $ceremony->verify($credentialJson, 'test-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsWhenUserVerificationRequiredButNotPerformed(): void
    {
        $config = new WebAuthnConfig(
            rpName: 'Test RP',
            rpId: 'example.com',
            origin: 'https://example.com',
            userVerification: 'required',
        );

        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony($config);

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x01"; // UP only, no UV
        $counter = "\x00\x00\x00\x01";
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User verification required but not performed');
        $ceremony->verify($credentialJson, 'test-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnCloneDetection(): void
    {
        // Credential has counter=10, and the new counter must be > 10
        $this->credentialRepo->persist($this->makeCredential('cred-1', 10));

        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x01"; // UP flag
        // Counter = 5, which is less than stored counter 10
        $counter = "\x00\x00\x00\x05";
        $authData = $rpIdHash . $flags . $counter;

        $credentialJson = json_encode([
            'rawId' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '='),
                'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('clone detected');
        $ceremony->verify($credentialJson, 'test-challenge', 'user-1');
    }

    #[Test]
    public function toArrayReturnsPublicKeyOptions(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();
        $options = $ceremony->generateOptions('user-1');
        $array = $options->toArray();

        self::assertSame($options->publicKeyOptions, $array);
    }

    #[Test]
    public function verifyUsesIdFallbackWhenRawIdMissing(): void
    {
        $this->credentialRepo->persist($this->makeCredential());

        $ceremony = $this->createCeremony();

        $credentialJson = json_encode([
            'id' => rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '='),
            // no 'rawId' key
            'response' => [
                'clientDataJSON' => rtrim(strtr(base64_encode('invalid'), '+/', '-_'), '='),
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        // Will fail on client data validation, but proves it found the credential using 'id'
        $this->expectExceptionMessage('Invalid client data JSON');
        $ceremony->verify($credentialJson, 'challenge', 'user-1');
    }

    #[Test]
    public function credentialSourceAlgorithmIdDefaultsToEs256(): void
    {
        $credential = $this->makeCredential();
        self::assertSame(-7, $credential->algorithmId);
    }

    #[Test]
    public function credentialSourceStoresCustomAlgorithmId(): void
    {
        $credential = $this->makeCredential(algorithmId: -257);
        self::assertSame(-257, $credential->algorithmId);
    }

    #[Test]
    public function unsupportedCoseAlgorithmThrowsOnMapping(): void
    {
        // Verify the coseAlgToOpenSsl static method throws for unknown algorithms
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Unsupported COSE algorithm');

        \Pulsar\Extension\WebAuthn\Adapter\AttestationVerifier::coseAlgToOpenSsl(-99);
    }
}
