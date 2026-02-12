<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Ceremony;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;
use Pulsar\Security\Audit\AuditEntry;

use function chr;
use function strlen;

final class RegistrationCeremonyTest extends TestCase
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

    private function createCeremony(?AttestationVerifierInterface $verifier = null): RegistrationCeremony
    {
        return new RegistrationCeremony(
            $this->config,
            $verifier ?? new AttestationVerifier(),
            $this->credentialRepo,
            $this->auditLogger,
        );
    }

    #[Test]
    public function generateOptionsReturnsValidStructure(): void
    {
        $ceremony = $this->createCeremony();

        $options = $ceremony->generateOptions('user-1', 'John Doe');

        self::assertNotEmpty($options->challenge);
        self::assertNotEmpty($options->publicKeyOptions);
        self::assertSame('Test RP', $options->publicKeyOptions['rp']['name']);
        self::assertSame('example.com', $options->publicKeyOptions['rp']['id']);
        self::assertSame('John Doe', $options->publicKeyOptions['user']['name']);
        self::assertSame('John Doe', $options->publicKeyOptions['user']['displayName']);
        self::assertSame($options->challenge, $options->publicKeyOptions['challenge']);
        self::assertSame(60000, $options->publicKeyOptions['timeout']);
        self::assertSame('preferred', $options->publicKeyOptions['authenticatorSelection']['userVerification']);
        self::assertSame('none', $options->publicKeyOptions['attestation']);
    }

    #[Test]
    public function generateOptionsWithExcludeCredentials(): void
    {
        $ceremony = $this->createCeremony();

        $options = $ceremony->generateOptions('user-1', 'John Doe', ['cred-1', 'cred-2']);

        self::assertCount(2, $options->publicKeyOptions['excludeCredentials']);
        self::assertSame('public-key', $options->publicKeyOptions['excludeCredentials'][0]['type']);
    }

    #[Test]
    public function generateOptionsIncludesPubKeyCredParams(): void
    {
        $ceremony = $this->createCeremony();

        $options = $ceremony->generateOptions('user-1', 'John Doe');

        $params = $options->publicKeyOptions['pubKeyCredParams'];
        self::assertCount(2, $params);
        self::assertSame(-7, $params[0]['alg']); // ES256
        self::assertSame(-257, $params[1]['alg']); // RS256
    }

    #[Test]
    public function verifyThrowsOnInvalidJson(): void
    {
        $ceremony = $this->createCeremony();

        $this->expectException(JsonException::class);
        $ceremony->verify('not-json', 'challenge');
    }

    #[Test]
    public function verifyThrowsOnInvalidClientDataJson(): void
    {
        $ceremony = $this->createCeremony();

        // Credential JSON with invalid base64url-encoded clientDataJSON
        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => base64_encode('invalid-json'),
                'attestationObject' => '',
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        // The base64-encoded 'invalid-json' will decode but json_decode will fail
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Invalid client data JSON');
        $ceremony->verify($credentialJson, 'challenge');
    }

    #[Test]
    public function verifyThrowsOnWrongClientDataType(): void
    {
        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.get', // wrong type, should be webauthn.create
            'challenge' => 'challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $clientDataB64 = rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '=');

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => $clientDataB64,
                'attestationObject' => '',
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage("Expected type 'webauthn.create'");
        $ceremony->verify($credentialJson, 'challenge');
    }

    #[Test]
    public function verifyThrowsOnChallengeMismatch(): void
    {
        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'wrong-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $clientDataB64 = rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '=');

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => $clientDataB64,
                'attestationObject' => '',
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('invalid or expired');
        $ceremony->verify($credentialJson, 'expected-challenge');
    }

    #[Test]
    public function verifyThrowsOnOriginMismatch(): void
    {
        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'test-challenge',
            'origin' => 'https://evil.com',
        ], JSON_THROW_ON_ERROR);

        $clientDataB64 = rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '=');

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => $clientDataB64,
                'attestationObject' => '',
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Origin mismatch');
        $ceremony->verify($credentialJson, 'test-challenge');
    }

    #[Test]
    public function verifyThrowsOnShortAuthenticatorData(): void
    {
        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $clientDataB64 = rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '=');

        // Build CBOR attestation object with short authData
        $shortAuthData = str_repeat("\x00", 10);
        $attObjCbor = $this->buildMinimalAttObjCbor('none', $shortAuthData);
        $attObjB64 = rtrim(strtr(base64_encode($attObjCbor), '+/', '-_'), '=');

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => $clientDataB64,
                'attestationObject' => $attObjB64,
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Authenticator data too short');
        $ceremony->verify($credentialJson, 'test-challenge');
    }

    #[Test]
    public function verifyThrowsOnRpIdHashMismatch(): void
    {
        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $clientDataB64 = rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '=');

        // Build authData with wrong rpIdHash
        $wrongRpIdHash = hash('sha256', 'wrong.com', true);
        $flags = "\x41"; // UP + AT
        $counter = "\x00\x00\x00\x00";
        $authData = $wrongRpIdHash . $flags . $counter;

        $attObjCbor = $this->buildMinimalAttObjCbor('none', $authData);
        $attObjB64 = rtrim(strtr(base64_encode($attObjCbor), '+/', '-_'), '=');

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => $clientDataB64,
                'attestationObject' => $attObjB64,
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('RP ID hash mismatch');
        $ceremony->verify($credentialJson, 'test-challenge');
    }

    #[Test]
    public function verifyThrowsWhenUserPresenceFlagNotSet(): void
    {
        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $clientDataB64 = rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '=');

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x40"; // AT flag set, but UP (0x01) not set
        $counter = "\x00\x00\x00\x00";
        $authData = $rpIdHash . $flags . $counter;

        $attObjCbor = $this->buildMinimalAttObjCbor('none', $authData);
        $attObjB64 = rtrim(strtr(base64_encode($attObjCbor), '+/', '-_'), '=');

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => $clientDataB64,
                'attestationObject' => $attObjB64,
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User presence flag not set');
        $ceremony->verify($credentialJson, 'test-challenge');
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

        $ceremony = new RegistrationCeremony(
            $config,
            new AttestationVerifier(),
            $this->credentialRepo,
            $this->auditLogger,
        );

        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $clientDataB64 = rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '=');

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41"; // UP + AT, but UV (0x04) not set
        $counter = "\x00\x00\x00\x00";
        $authData = $rpIdHash . $flags . $counter;

        $attObjCbor = $this->buildMinimalAttObjCbor('none', $authData);
        $attObjB64 = rtrim(strtr(base64_encode($attObjCbor), '+/', '-_'), '=');

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => $clientDataB64,
                'attestationObject' => $attObjB64,
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User verification required but not performed');
        $ceremony->verify($credentialJson, 'test-challenge');
    }

    #[Test]
    public function verifyThrowsWhenAttestedCredentialDataFlagNotSet(): void
    {
        $ceremony = $this->createCeremony();

        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $clientDataB64 = rtrim(strtr(base64_encode($clientDataJson), '+/', '-_'), '=');

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x01"; // UP only, no AT (0x40) flag
        $counter = "\x00\x00\x00\x00";
        $authData = $rpIdHash . $flags . $counter;

        $attObjCbor = $this->buildMinimalAttObjCbor('none', $authData);
        $attObjB64 = rtrim(strtr(base64_encode($attObjCbor), '+/', '-_'), '=');

        $credentialJson = json_encode([
            'response' => [
                'clientDataJSON' => $clientDataB64,
                'attestationObject' => $attObjB64,
            ],
            'rawId' => '',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Attested credential data flag not set');
        $ceremony->verify($credentialJson, 'test-challenge');
    }

    private function buildMinimalAttObjCbor(string $fmt, string $authData): string
    {
        $encoded = "\xa3"; // map(3)

        // fmt
        $fmtLen = strlen($fmt);
        $encoded .= "\x63" . 'fmt';
        $encoded .= chr(0x60 | $fmtLen) . $fmt;

        // attStmt: empty map
        $encoded .= "\x67" . 'attStmt';
        $encoded .= "\xa0";

        // authData: byte string
        $encoded .= "\x68" . 'authData';
        $authDataLen = strlen($authData);
        if ($authDataLen < 24) {
            $encoded .= chr(0x40 | $authDataLen) . $authData;
        } elseif ($authDataLen < 256) {
            $encoded .= "\x58" . chr($authDataLen) . $authData;
        } else {
            $encoded .= "\x59" . pack('n', $authDataLen) . $authData;
        }

        return $encoded;
    }
}
