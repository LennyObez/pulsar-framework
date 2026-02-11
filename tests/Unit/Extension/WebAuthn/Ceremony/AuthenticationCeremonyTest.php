<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Webauthn\Ceremony;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationOptions;
use Pulsar\Extension\WebAuthn\Ceremony\AuthenticationResult;
use Pulsar\Extension\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(AuthenticationCeremony::class)]
final class AuthenticationCeremonyTest extends TestCase
{
    private WebAuthnConfig $config;
    private CredentialRepositoryInterface & Stub $credentialRepository;
    private AuditLoggerInterface & Stub $auditLogger;
    private AuthenticationCeremony $ceremony;

    protected function setUp(): void
    {
        $this->config = new WebAuthnConfig(
            rpName: 'TestApp',
            rpId: 'example.com',
            origin: 'https://example.com',
            userVerification: 'preferred',
            timeout: 60000,
        );

        $this->credentialRepository = $this->createStub(CredentialRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        $this->ceremony = new AuthenticationCeremony(
            $this->config,
            $this->credentialRepository,
            $this->auditLogger,
        );
    }

    // --- generateOptions tests ---

    #[Test]
    public function generateOptionsForDiscoverableFlowReturnsOptionsWithoutAllowCredentials(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authentication,
                AuditOutcome::Success,
                null,
                'webauthn.authentication.started',
                'webauthn:authentication',
                self::callback(fn(array $meta): bool => $meta['discoverable'] === true && $meta['user_id'] === null),
            );

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $this->credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions(null);

        self::assertInstanceOf(AuthenticationOptions::class, $options);
        self::assertNotEmpty($options->challenge);
        self::assertSame(60000, $options->publicKeyOptions['timeout']);
        self::assertSame('example.com', $options->publicKeyOptions['rpId']);
        self::assertSame('preferred', $options->publicKeyOptions['userVerification']);
        self::assertArrayNotHasKey('allowCredentials', $options->publicKeyOptions);
    }

    #[Test]
    public function generateOptionsForUserWithCredentialsIncludesAllowCredentials(): void
    {
        $cred1 = $this->makeCredentialSource('cred-1', 'user-1', ['internal']);
        $cred2 = $this->makeCredentialSource('cred-2', 'user-1', ['usb', 'nfc']);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findByUserId')
            ->with('user-1')
            ->willReturn([$cred1, $cred2]);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $auditLogger,
        );

        $options = $ceremony->generateOptions('user-1');

        $allowCreds = $options->publicKeyOptions['allowCredentials'];
        assert(is_array($allowCreds));
        self::assertCount(2, $allowCreds);
        $cred0 = $allowCreds[0];
        assert(is_array($cred0));
        $cred1 = $allowCreds[1];
        assert(is_array($cred1));
        self::assertSame('public-key', $cred0['type']);
        self::assertSame(['internal'], $cred0['transports']);
        self::assertSame(['usb', 'nfc'], $cred1['transports']);
    }

    #[Test]
    public function generateOptionsThrowsWhenUserHasNoCredentials(): void
    {
        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findByUserId')
            ->with('user-empty')
            ->willReturn([]);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('No credentials found for the user');

        $ceremony->generateOptions('user-empty');
    }

    #[Test]
    public function generateOptionsProducesUniqueChallenges(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::exactly(2))->method('log');

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $this->credentialRepository,
            $auditLogger,
        );

        $opts1 = $ceremony->generateOptions(null);
        $opts2 = $ceremony->generateOptions(null);

        self::assertNotSame($opts1->challenge, $opts2->challenge);
    }

    // --- verify tests ---

    #[Test]
    public function verifyThrowsOnInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        $this->ceremony->verify('{{invalid', 'challenge');
    }

    #[Test]
    public function verifyThrowsWhenCredentialNotFound(): void
    {
        $rawId = $this->base64UrlEncode('unknown-cred');
        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => '',
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn(null);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('credential is not registered');

        $ceremony->verify($credential, 'challenge');
    }

    #[Test]
    public function verifyThrowsWhenCredentialBelongsToDifferentUser(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-A', []);
        $rawId = $this->base64UrlEncode('cred-1');

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => '',
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('does not belong to the expected user');

        $ceremony->verify($credential, 'challenge', 'user-B');
    }

    #[Test]
    public function verifyThrowsOnInvalidClientDataJson(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode("\x00\x01\x02"),
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Invalid client data JSON');

        $ceremony->verify($credential, 'challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnWrongCeremonyType(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');

        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage("Expected type 'webauthn.get'");

        $ceremony->verify($credential, 'test-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnChallengeMismatch(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => 'wrong-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('challenge');

        $ceremony->verify($credential, 'expected-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnOriginMismatch(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://evil.com',
        ], JSON_THROW_ON_ERROR);

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => '',
                'signature' => '',
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Origin mismatch');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyThrowsOnAuthDataTooShort(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $shortAuthData = str_repeat("\x00", 10);

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode($shortAuthData),
                'signature' => $this->base64UrlEncode('sig'),
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Authenticator data too short');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyThrowsOnRpIdHashMismatch(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        // Wrong RP ID hash
        $authData = str_repeat("\xFF", 32) . chr(0x01) . pack('N', 1);

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode($authData),
                'signature' => $this->base64UrlEncode('sig'),
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('RP ID hash mismatch');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyThrowsWhenUserPresenceFlagNotSet(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $authData = $rpIdHash . chr(0x00) . pack('N', 1); // No flags set

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode($authData),
                'signature' => $this->base64UrlEncode('sig'),
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User presence flag not set');

        $ceremony->verify($credential, $challenge, 'user-1');
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

        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        // UP set but NOT UV
        $authData = $rpIdHash . chr(0x01) . pack('N', 1);

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode($authData),
                'signature' => $this->base64UrlEncode('sig'),
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony($config, $credentialRepository, $this->auditLogger);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User verification required but not performed');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyDetectsCloneWhenCounterDoesNotIncrease(): void
    {
        // Credential with stored counter = 5
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', [], signatureCounter: 5);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x01 | 0x04); // UP + UV
        // New counter = 3, which is less than stored counter 5
        $authData = $rpIdHash . $flags . pack('N', 3);

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode($authData),
                'signature' => $this->base64UrlEncode('sig'),
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        // Should log a security event for clone detection
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::exactly(2))
            ->method('log');

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('clone detected');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyDetectsCloneWhenCounterEqualsStoredCounter(): void
    {
        // Counter = 5 on both sides (not increasing)
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', [], signatureCounter: 5);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x01 | 0x04); // UP + UV
        $authData = $rpIdHash . $flags . pack('N', 5); // Same counter

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode($authData),
                'signature' => $this->base64UrlEncode('sig'),
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('clone detected');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyAllowsBothZeroCounters(): void
    {
        // When both stored and new counter are 0, counter is unsupported — no clone detection
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', [], signatureCounter: 0);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x01); // UP
        $authData = $rpIdHash . $flags . pack('N', 0); // Counter = 0

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode($authData),
                'signature' => $this->base64UrlEncode('sig'),
            ],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        // Will fail at signature verification since we don't have a real key,
        // but it should NOT fail on clone detection
        try {
            $ceremony->verify($credential, $challenge, 'user-1');
        } catch (WebAuthnException $e) {
            // Acceptable: we expect it to fail at signature verification, not clone detection
            self::assertStringNotContainsString('clone', $e->getMessage());
        }
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
                'user-test',
                'webauthn.authentication.failed',
                'webauthn:authentication',
                self::callback(fn(array $meta): bool => isset($meta['error']) && isset($meta['error_code'])),
            );

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn(null);

        $credential = json_encode([
            'rawId' => $this->base64UrlEncode('cred-1'),
            'response' => ['clientDataJSON' => '', 'authenticatorData' => '', 'signature' => ''],
        ], JSON_THROW_ON_ERROR);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $auditLogger,
        );

        try {
            $ceremony->verify($credential, 'challenge', 'user-test');
        } catch (WebAuthnException) {
            // Expected
        }
    }

    #[Test]
    public function verifyUsesIdKeyWhenRawIdMissing(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $credId = $this->base64UrlEncode('cred-1');

        $credential = json_encode([
            'id' => $credId,
            'response' => ['clientDataJSON' => '', 'authenticatorData' => '', 'signature' => ''],
        ], JSON_THROW_ON_ERROR);

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->with('cred-1')
            ->willReturn($credSource);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        // Will fail at clientData validation, but the credential lookup should use 'id' field
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Invalid client data JSON');

        $ceremony->verify($credential, 'challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsWhenUserHandleDoesNotMatchCredentialOwner(): void
    {
        // This tests the discoverable flow where userHandle is checked
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = 'test-challenge';

        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = chr(0x01); // UP
        $authData = $rpIdHash . $flags . pack('N', 0);

        // Generate an EC key pair for signature verification
        $keyRes = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($keyRes === false) {
            self::markTestSkipped('OpenSSL EC key generation not available');
        }
        $keyDetails = openssl_pkey_get_details($keyRes);
        assert($keyDetails !== false);
        $publicKeyPem = $keyDetails['key'];
        assert(is_string($publicKeyPem));

        $credSource = new CredentialSource(
            credentialId: 'cred-1',
            userId: 'user-REAL',
            publicKeyPem: $publicKeyPem,
            signatureCounter: 0,
            attestationFormat: 'none',
            transports: [],
            discoverable: true,
            aaguid: '',
            createdAt: new DateTimeImmutable(),
        );

        // Sign the data properly
        $clientDataHash = hash('sha256', $clientData, true);
        $signedData = $authData . $clientDataHash;
        $signature = '';
        openssl_sign($signedData, $signature, $keyRes, OPENSSL_ALGO_SHA256);
        assert(is_string($signature));

        $credentialRepository = $this->createMock(CredentialRepositoryInterface::class);
        $credentialRepository->expects(self::once())
            ->method('findById')
            ->willReturn($credSource);

        $credentialRepository->expects(self::once())
            ->method('updateCounter');

        $credential = json_encode([
            'rawId' => $rawId,
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode($authData),
                'signature' => $this->base64UrlEncode($signature),
                'userHandle' => $this->base64UrlEncode('user-IMPERSONATOR'),
            ],
        ], JSON_THROW_ON_ERROR);

        $ceremony = new AuthenticationCeremony(
            $this->config,
            $credentialRepository,
            $this->auditLogger,
        );

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('User handle does not match credential owner');

        // Null expectedUserId = discoverable flow, userHandle will be checked
        $ceremony->verify($credential, $challenge, null);
    }

    // --- Helper methods ---

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param list<string> $transports
     */
    private function makeCredentialSource(
        string $credentialId,
        string $userId,
        array $transports,
        int $signatureCounter = 0,
    ): CredentialSource {
        return new CredentialSource(
            credentialId: $credentialId,
            userId: $userId,
            publicKeyPem: "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----\n",
            signatureCounter: $signatureCounter,
            attestationFormat: 'none',
            transports: $transports,
            discoverable: false,
            aaguid: 'test-aaguid',
            createdAt: new DateTimeImmutable(),
        );
    }
}
