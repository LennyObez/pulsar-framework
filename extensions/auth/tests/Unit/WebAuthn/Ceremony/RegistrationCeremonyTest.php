<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Ceremony;

use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\WebAuthn\Adapter\CborDecoder;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationOptions;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function assert;
use function chr;
use function count;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function strlen;

#[CoversClass(RegistrationCeremony::class)]
final class RegistrationCeremonyTest extends TestCase
{
    private WebAuthnConfig $config;
    private AttestationVerifierInterface & Stub $attestationVerifier;
    private CredentialRepositoryInterface & Stub $credentialRepository;
    private AuditLoggerInterface & Stub $auditLogger;
    private RegistrationCeremony $ceremony;

    protected function setUp(): void
    {
        $this->config = new WebAuthnConfig(
            rpName: 'TestApp',
            rpId: 'example.com',
            origin: 'https://example.com',
            userVerification: 'preferred',
            attestation: 'none',
            timeout: 60000,
        );

        $this->attestationVerifier = $this->createStub(AttestationVerifierInterface::class);
        $this->credentialRepository = $this->createStub(CredentialRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        $this->ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $this->auditLogger,
        );
    }

    // --- generateOptions tests ---

    #[Test]
    public function generateOptionsReturnsValidRegistrationOptions(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions('user-123', 'John Doe');

        self::assertInstanceOf(RegistrationOptions::class, $options);
        self::assertNotEmpty($options->challenge);
        self::assertArrayHasKey('rp', $options->publicKeyOptions);

        /** @var array{name: string, id: string} $rp */
        $rp = $options->publicKeyOptions['rp'];
        self::assertSame('TestApp', $rp['name']);
        self::assertSame('example.com', $rp['id']);
    }

    #[Test]
    public function generateOptionsIncludesUserInfo(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions('user-abc', 'Jane Smith');

        /** @var array{name: string, displayName: string, id: string} $userOpts */
        $userOpts = $options->publicKeyOptions['user'];
        self::assertSame('Jane Smith', $userOpts['name']);
        self::assertSame('Jane Smith', $userOpts['displayName']);
        self::assertNotEmpty($userOpts['id']); // base64url-encoded user ID
    }

    #[Test]
    public function generateOptionsIncludesPubKeyCredParams(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions('u', 'u');

        /** @var list<array{alg: int, type: string}> $params */
        $params = $options->publicKeyOptions['pubKeyCredParams'];
        self::assertCount(2, $params);
        self::assertSame(-7, $params[0]['alg']);   // ES256
        self::assertSame(-257, $params[1]['alg']); // RS256
    }

    #[Test]
    public function generateOptionsIncludesTimeoutAndAttestation(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions('u', 'u');

        self::assertSame(60000, $options->publicKeyOptions['timeout']);
        self::assertSame('none', $options->publicKeyOptions['attestation']);
    }

    #[Test]
    public function generateOptionsIncludesAuthenticatorSelection(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions('u', 'u');

        /** @var array{residentKey: string, requireResidentKey: bool, userVerification: string} $authSel */
        $authSel = $options->publicKeyOptions['authenticatorSelection'];
        self::assertSame('preferred', $authSel['residentKey']);
        self::assertFalse($authSel['requireResidentKey']);
        self::assertSame('preferred', $authSel['userVerification']);
    }

    #[Test]
    public function generateOptionsExcludesExistingCredentials(): void
    {
        $credId = 'existing-cred-id';
        $credSource = new CredentialSource(
            credentialId: $credId,
            userId: 'user-1',
            publicKeyPem: 'pem',
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: ['internal', 'usb'],
            discoverable: true,
            aaguid: 'test-aaguid',
            createdAt: new DateTimeImmutable(),
        );

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->with($credId)
            ->willReturn($credSource);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions('user-1', 'User', [$credId]);

        /** @var list<array{type: string, id: string, transports: list<string>}> $excluded */
        $excluded = $options->publicKeyOptions['excludeCredentials'];
        self::assertCount(1, $excluded);
        self::assertSame('public-key', $excluded[0]['type']);
        self::assertSame(['internal', 'usb'], $excluded[0]['transports']);
    }

    #[Test]
    public function generateOptionsHandlesNullCredentialGracefully(): void
    {
        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn(null);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions('user-1', 'User', ['missing-cred']);

        /** @var list<array{type: string, id: string, transports: list<string>}> $excluded */
        $excluded = $options->publicKeyOptions['excludeCredentials'];
        self::assertCount(1, $excluded);
        self::assertSame([], $excluded[0]['transports']);
    }

    #[Test]
    public function generateOptionsLogsAuditEvent(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authentication,
                AuditOutcome::Success,
                'user-42',
                'webauthn.registration.started',
                'webauthn:registration',
                self::callback(fn(array $meta): bool => $meta['user_id'] === 'user-42'),
            );

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        $ceremony->generateOptions('user-42', 'User');
    }

    #[Test]
    public function generateOptionsProducesUniqueChallengPerCall(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::exactly(2))->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        $options1 = $ceremony->generateOptions('u', 'u');
        $options2 = $ceremony->generateOptions('u', 'u');

        self::assertNotSame($options1->challenge, $options2->challenge);
    }

    // --- verify tests ---

    #[Test]
    public function verifyThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonException::class);

        $this->ceremony->verify('not-valid-json{{{', 'challenge');
    }

    #[Test]
    public function verifyThrowsOnInvalidClientDataJson(): void
    {
        $credential = $this->buildCredentialJson(
            clientDataJson: 'not-valid',
            attestationObject: '',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Invalid client data JSON');

        $this->ceremony->verify($credential, 'expected-challenge');
    }

    #[Test]
    public function verifyThrowsOnWrongCeremonyType(): void
    {
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $credential = $this->buildCredentialJson(
            clientDataJson: $clientData,
            attestationObject: '',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage("Expected type 'webauthn.create'");

        $this->ceremony->verify($credential, 'test-challenge');
    }

    #[Test]
    public function verifyThrowsOnChallengeMismatch(): void
    {
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'wrong-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $credential = $this->buildCredentialJson(
            clientDataJson: $clientData,
            attestationObject: '',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('challenge');

        $this->ceremony->verify($credential, 'expected-challenge');
    }

    #[Test]
    public function verifyThrowsOnOriginMismatch(): void
    {
        $challenge = 'test-challenge';
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://evil.com',
        ], JSON_THROW_ON_ERROR);

        $credential = $this->buildCredentialJson(
            clientDataJson: $clientData,
            attestationObject: '',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Origin mismatch');

        $this->ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsOnAuthDataTooShort(): void
    {
        $challenge = 'test-challenge';
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // Build a minimal CBOR-like attestation with very short authData
        $shortAuthData = str_repeat("\x00", 10);
        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $clientData,
            authData: $shortAuthData,
            fmt: 'none',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Authenticator data too short');

        $this->ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsOnRpIdHashMismatch(): void
    {
        $challenge = 'test-challenge';
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // AuthData with wrong RP ID hash (32 zero bytes), UP flag set, attested data flag set
        $flags = chr(0x01 | 0x40); // UP + AT
        $authData = str_repeat("\x00", 32) . $flags . str_repeat("\x00", 4);

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $clientData,
            authData: $authData,
            fmt: 'none',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('RP ID hash mismatch');

        $this->ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsWhenUserPresenceFlagNotSet(): void
    {
        $challenge = 'test-challenge';
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x40); // AT flag set but NOT UP
        $counter = pack('N', 0);
        $authData = $rpIdHash . $flags . $counter;

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $clientData,
            authData: $authData,
            fmt: 'none',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User presence flag not set');

        $this->ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsWhenUserVerificationRequiredButNotPerformed(): void
    {
        $config = new WebAuthnConfig(
            rpName: 'TestApp',
            rpId: 'example.com',
            origin: 'https://example.com',
            userVerification: 'required',
        );

        $ceremony = new RegistrationCeremony(
            $config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $this->auditLogger,
        );

        $challenge = 'test-challenge';
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        // UP + AT flags set, but NOT UV
        $flags = chr(0x01 | 0x40);
        $counter = pack('N', 0);
        $authData = $rpIdHash . $flags . $counter;

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $clientData,
            authData: $authData,
            fmt: 'none',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User verification required but not performed');

        $ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsWhenAttestedCredentialDataFlagNotSet(): void
    {
        $challenge = 'test-challenge';
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x01); // UP only, no AT flag
        $counter = pack('N', 0);
        $authData = $rpIdHash . $flags . $counter;

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $clientData,
            authData: $authData,
            fmt: 'none',
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Attested credential data flag not set');

        $this->ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyLogsFailureOnException(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authentication,
                AuditOutcome::Failure,
                // F25.10 carry-over: production code passes
                // AuditActor::anonymous() (the explicit-actor pattern
                // mandated since rc.10) instead of null. Match the
                // typed actor object the framework actually emits.
                self::callback(fn(mixed $actor): bool => $actor instanceof AuditActor && $actor->id === 'anonymous'),
                'webauthn.registration.failed',
                'webauthn:registration',
                self::callback(fn(array $meta): bool => isset($meta['error']) && isset($meta['error_code'])),
            );

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        try {
            $ceremony->verify('{}', 'challenge');
        } catch (WebAuthnException) {
            // Expected
        }
    }

    #[Test]
    public function verifyRethrowsWebAuthnExceptionAfterLogging(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new RegistrationCeremony(
            $this->config,
            $this->attestationVerifier,
            $this->credentialRepository,
            $auditLogger,
        );

        $this->expectException(WebAuthnException::class);

        $ceremony->verify('{}', 'challenge');
    }

    // --- Helper methods ---

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function buildCredentialJson(string $clientDataJson, string $attestationObject): string
    {
        return json_encode([
            'rawId' => $this->base64UrlEncode('test-raw-id'),
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientDataJson),
                'attestationObject' => $this->base64UrlEncode($attestationObject),
            ],
            'userId' => 'user-1',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Build credential JSON with a CBOR-encoded attestation object.
     */
    private function buildCredentialJsonWithCbor(string $clientDataJson, string $authData, string $fmt): string
    {
        // Minimal CBOR map: {"fmt": $fmt, "authData": $authData, "attStmt": {}}
        // We can't easily build real CBOR inline, so we'll use the CborDecoder's format.
        // Instead, manually encode a minimal CBOR map.
        $cborMap = $this->encodeCborMap([
            'fmt' => $fmt,
            'authData' => $authData,
            'attStmt' => [],
        ]);

        return json_encode([
            'rawId' => $this->base64UrlEncode('test-raw-id'),
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientDataJson),
                'attestationObject' => $this->base64UrlEncode($cborMap),
            ],
            'userId' => 'user-1',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Minimal CBOR encoder for test attestation objects.
     *
     * @param array<string, mixed> $map
     */
    private function encodeCborMap(array $map): string
    {
        $count = count($map);
        assert($count < 24);
        // Major type 5 (map), additional info = count
        $result = chr(0xA0 | $count);

        foreach ($map as $key => $value) {
            // Text string key
            $result .= $this->encodeCborText($key);
            // Value
            $result .= $this->encodeCborValue($value);
        }

        return $result;
    }

    private function encodeCborText(string $text): string
    {
        $len = strlen($text);
        if ($len < 24) {
            return chr(0x60 | $len) . $text;
        }

        assert($len < 256);

        return chr(0x78) . chr($len) . $text;
    }

    private function encodeCborByteString(string $bytes): string
    {
        $len = strlen($bytes);
        if ($len < 24) {
            return chr(0x40 | $len) . $bytes;
        }
        if ($len < 256) {
            return chr(0x58) . chr($len) . $bytes;
        }

        return chr(0x59) . pack('n', $len) . $bytes;
    }

    private function encodeCborValue(mixed $value): string
    {
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            // Binary data -> byte string
            return $this->encodeCborByteString($value);
        }

        if (is_string($value)) {
            // Could be binary authData masquerading as string
            if (str_contains($value, "\x00") || strlen($value) > 0 && !ctype_print($value)) {
                return $this->encodeCborByteString($value);
            }

            return $this->encodeCborText($value);
        }

        if (is_array($value) && $value === []) {
            return "\xA0"; // empty map
        }

        if (is_int($value) && $value >= 0 && $value < 24) {
            return chr($value);
        }

        assert(is_scalar($value));

        return $this->encodeCborText((string) $value);
    }
}
