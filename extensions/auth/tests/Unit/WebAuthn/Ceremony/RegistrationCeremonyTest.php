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
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryChallengeStore;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\Challenge;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationOptions;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\ChallengeStoreInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Testing\Clock\TestClock;

use function assert;
use function chr;
use function count;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function str_repeat;
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
        $this->expectExceptionMessageIsOrContains('Invalid client data JSON');

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
        $this->expectExceptionMessageIsOrContains("Expected type 'webauthn.create'");

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
        $this->expectExceptionMessageIsOrContains('challenge');

        $this->ceremony->verify($credential, 'expected-challenge');
    }

    #[Test]
    public function verifyThrowsOnOriginMismatch(): void
    {
        $challenge = $this->freshChallenge();
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
        $this->expectExceptionMessageIsOrContains('Origin mismatch');

        $this->ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsOnAuthDataTooShort(): void
    {
        $challenge = $this->freshChallenge();
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
        $this->expectExceptionMessageIsOrContains('Authenticator data too short');

        $this->ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsOnRpIdHashMismatch(): void
    {
        $challenge = $this->freshChallenge();
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
        $this->expectExceptionMessageIsOrContains('RP ID hash mismatch');

        $this->ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsWhenUserPresenceFlagNotSet(): void
    {
        $challenge = $this->freshChallenge();
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
        $this->expectExceptionMessageIsOrContains('User presence flag not set');

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

        $challenge = $this->freshChallenge();
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
        $this->expectExceptionMessageIsOrContains('User verification required but not performed');

        $ceremony->verify($credential, $challenge);
    }

    #[Test]
    public function verifyThrowsWhenAttestedCredentialDataFlagNotSet(): void
    {
        $challenge = $this->freshChallenge();
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
        $this->expectExceptionMessageIsOrContains('Attested credential data flag not set');

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
                // Audit logging uses the explicit-actor pattern: an
                // unauthenticated ceremony emits AuditActor::anonymous(),
                // never null. Match the typed actor object, not a null.
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

    // --- challenge_ttl_seconds enforcement ---

    /**
     * The config key existed and was parsed into WebAuthnConfig, but no ceremony
     * read it: an attestation answering a challenge minted an hour earlier was
     * accepted exactly like one minted a second earlier.
     */
    #[Test]
    public function verifyRefusesAnAttestationAnsweringAChallengePastItsTtl(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $ceremony = $this->ceremonyWithTtl(300, $clock);

        $challenge = $ceremony->generateOptions('user-1', 'User One')->challenge;

        $clock->advance(seconds: 301);

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $this->clientDataFor($challenge),
            authData: str_repeat("\x00", 10),
            fmt: 'none',
        );

        try {
            $ceremony->verify($credential, $challenge, 'user-1');
            self::fail('An attestation answering an expired challenge must be refused.');
        } catch (WebAuthnException $e) {
            self::assertSame('expired_challenge', $e->errorCode());
        }
    }

    /**
     * The boundary is inclusive, and the refusal above is not simply "the
     * ceremony rejects everything": one second earlier the same response walks
     * past the challenge gate and is refused for the next reason instead.
     */
    #[Test]
    public function verifyAcceptsAChallengeOnTheFinalSecondOfItsTtl(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $ceremony = $this->ceremonyWithTtl(300, $clock);

        $challenge = $ceremony->generateOptions('user-1', 'User One')->challenge;

        $clock->advance(seconds: 300);

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $this->clientDataFor($challenge),
            authData: str_repeat("\x00", 10),
            fmt: 'none',
        );

        try {
            $ceremony->verify($credential, $challenge, 'user-1');
            self::fail('The short authenticator data must still be refused.');
        } catch (WebAuthnException $e) {
            self::assertSame('invalid_attestation', $e->errorCode());
            self::assertStringContainsString('Authenticator data too short', $e->getMessage());
        }
    }

    /**
     * A challenge this relying party never minted has no issuance instant, so it
     * cannot be shown to be inside the window. Treating it as unlimited would
     * hand any caller a way around the TTL by inventing its own challenge.
     */
    #[Test]
    public function verifyRefusesAChallengeThisServerNeverIssued(): void
    {
        $ceremony = $this->ceremonyWithTtl(300, TestClock::at('2026-03-01T12:00:00+00:00'));

        $challenge = 'hand-rolled-challenge';

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $this->clientDataFor($challenge),
            authData: str_repeat("\x00", 10),
            fmt: 'none',
        );

        try {
            $ceremony->verify($credential, $challenge, 'user-1');
            self::fail('A challenge outside the server-minted format must be refused.');
        } catch (WebAuthnException $e) {
            self::assertSame('expired_challenge', $e->errorCode());
        }
    }

    #[Test]
    public function generatedChallengeCarriesItsIssuanceInstant(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $ceremony = $this->ceremonyWithTtl(300, $clock);

        $challenge = $ceremony->generateOptions('user-1', 'User One')->challenge;

        $issuedAt = Challenge::issuedAt($challenge);
        self::assertNotNull($issuedAt);
        self::assertSame($clock->timestamp(), $issuedAt->getTimestamp());
    }

    /**
     * Registration is replayable on the same terms authentication was: the TTL
     * bounds how long a captured attestation stays usable and nothing marked the
     * challenge spent inside that window. It matters as much here as on the
     * assertion side, because an attestation replayed against a relying party is
     * an attempt to attach an authenticator a second time.
     *
     * The clock moves one second, not past the window, and freshness is asserted
     * at the second attempt so the refusal cannot be the TTL wearing a new error
     * code.
     */
    #[Test]
    public function verifyRefusesASecondAttestationAnsweringTheSameChallengeInsideItsTtl(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $ceremony = $this->ceremonyWithTtl(300, $clock);

        $challenge = $ceremony->generateOptions('user-1', 'User One')->challenge;

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $this->clientDataFor($challenge),
            authData: str_repeat("\x00", 10),
            fmt: 'none',
        );

        // First answer: past the challenge gate, refused for the next reason.
        try {
            $ceremony->verify($credential, $challenge, 'user-1');
            self::fail('The short authenticator data must be refused.');
        } catch (WebAuthnException $e) {
            self::assertSame('invalid_attestation', $e->errorCode());
        }

        $clock->advance(seconds: 1);

        self::assertTrue(
            Challenge::isFresh($challenge, 300, $clock->now()),
            'The replay must be attempted while the challenge is still inside its window.',
        );

        try {
            $ceremony->verify($credential, $challenge, 'user-1');
            self::fail('A challenge that was already answered must be refused the second time.');
        } catch (WebAuthnException $e) {
            self::assertSame('replayed_challenge', $e->errorCode());
        }
    }

    /**
     * Spending one challenge must not spend the next. Without this the test above
     * would also pass against a store that refuses everything.
     */
    #[Test]
    public function spendingOneRegistrationChallengeLeavesTheNextOneUsable(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $ceremony = $this->ceremonyWithTtl(300, $clock);

        $first = $ceremony->generateOptions('user-1', 'User One')->challenge;
        $second = $ceremony->generateOptions('user-1', 'User One')->challenge;

        self::assertNotSame($first, $second);

        foreach ([$first, $second] as $challenge) {
            $credential = $this->buildCredentialJsonWithCbor(
                clientDataJson: $this->clientDataFor($challenge),
                authData: str_repeat("\x00", 10),
                fmt: 'none',
            );

            try {
                $ceremony->verify($credential, $challenge, 'user-1');
                self::fail('The short authenticator data must be refused.');
            } catch (WebAuthnException $e) {
                self::assertSame('invalid_attestation', $e->errorCode());
            }
        }
    }

    /**
     * The two ceremonies do not share a store by default, and must not be assumed
     * to: each holds its own unless a deployment binds one. What they DO share,
     * when handed the same store, is the namespace of spent challenges — so a
     * registration challenge cannot be re-presented to the authentication
     * ceremony either. The `webauthn.create` / `webauthn.get` type check already
     * separates the two flows; this states that the store does not quietly
     * re-open a path between them.
     */
    #[Test]
    public function aSharedStoreSpendsAChallengeForBothCeremonies(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $store = new InMemoryChallengeStore($clock);

        $ceremony = $this->ceremonyWithTtl(300, $clock, $store);
        $other = $this->ceremonyWithTtl(300, $clock, $store);

        $challenge = $ceremony->generateOptions('user-1', 'User One')->challenge;

        $credential = $this->buildCredentialJsonWithCbor(
            clientDataJson: $this->clientDataFor($challenge),
            authData: str_repeat("\x00", 10),
            fmt: 'none',
        );

        try {
            $ceremony->verify($credential, $challenge, 'user-1');
            self::fail('The short authenticator data must be refused.');
        } catch (WebAuthnException $e) {
            self::assertSame('invalid_attestation', $e->errorCode());
        }

        try {
            $other->verify($credential, $challenge, 'user-1');
            self::fail('A shared store must refuse the replay at the second ceremony too.');
        } catch (WebAuthnException $e) {
            self::assertSame('replayed_challenge', $e->errorCode());
        }
    }

    private function ceremonyWithTtl(
        int $ttlSeconds,
        TestClock $clock,
        ?ChallengeStoreInterface $challengeStore = null,
    ): RegistrationCeremony {
        return new RegistrationCeremony(
            new WebAuthnConfig(
                rpName: 'TestApp',
                rpId: 'example.com',
                origin: 'https://example.com',
                userVerification: 'preferred',
                attestation: 'none',
                challengeTtlSeconds: $ttlSeconds,
                timeout: 60000,
            ),
            $this->attestationVerifier,
            $this->credentialRepository,
            $this->auditLogger,
            $clock,
            $challengeStore,
        );
    }

    /**
     * @throws JsonException
     */
    private function clientDataFor(string $challenge): string
    {
        return json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);
    }

    private function freshChallenge(): string
    {
        return Challenge::issue(new DateTimeImmutable());
    }
}
