<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Ceremony;

use DateTimeImmutable;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryChallengeStore;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationOptions;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\Challenge;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\ChallengeStoreInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Testing\Clock\TestClock;

use function assert;
use function chr;
use function is_array;
use function is_string;

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
        $this->expectExceptionMessageIsOrContains('No credentials found for the user');

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
        $this->expectException(JsonException::class);

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
        $this->expectExceptionMessageIsOrContains('credential is not registered');

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
        $this->expectExceptionMessageIsOrContains('does not belong to the expected user');

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
        $this->expectExceptionMessageIsOrContains('Invalid client data JSON');

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
        $this->expectExceptionMessageIsOrContains("Expected type 'webauthn.get'");

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
        $this->expectExceptionMessageIsOrContains('challenge');

        $ceremony->verify($credential, 'expected-challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsOnOriginMismatch(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('Origin mismatch');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyThrowsOnAuthDataTooShort(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('Authenticator data too short');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyThrowsOnRpIdHashMismatch(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('RP ID hash mismatch');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyThrowsWhenUserPresenceFlagNotSet(): void
    {
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', []);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('User presence flag not set');

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
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('User verification required but not performed');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyDetectsCloneWhenCounterDoesNotIncrease(): void
    {
        // Credential with stored counter = 5
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', [], signatureCounter: 5);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('clone detected');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyDetectsCloneWhenCounterEqualsStoredCounter(): void
    {
        // Counter = 5 on both sides (not increasing)
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', [], signatureCounter: 5);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('clone detected');

        $ceremony->verify($credential, $challenge, 'user-1');
    }

    #[Test]
    public function verifyAllowsBothZeroCounters(): void
    {
        // When both stored and new counter are 0, counter is unsupported: no clone detection
        $credSource = $this->makeCredentialSource('cred-1', 'user-1', [], signatureCounter: 0);
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('Invalid client data JSON');

        $ceremony->verify($credential, 'challenge', 'user-1');
    }

    #[Test]
    public function verifyThrowsWhenUserHandleDoesNotMatchCredentialOwner(): void
    {
        // This tests the discoverable flow where userHandle is checked
        $rawId = $this->base64UrlEncode('cred-1');
        $challenge = $this->freshChallenge();

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
        $this->expectExceptionMessageIsOrContains('User handle does not match credential owner');

        // Null expectedUserId = discoverable flow, userHandle will be checked
        $ceremony->verify($credential, $challenge, null);
    }

    // --- challenge_ttl_seconds enforcement ---

    /**
     * The config key existed and was parsed into WebAuthnConfig, but no ceremony
     * read it: an assertion answering a challenge minted an hour earlier was
     * accepted exactly like one minted a second earlier.
     */
    #[Test]
    public function verifyRefusesAnAssertionAnsweringAChallengePastItsTtl(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $ceremony = $this->ceremonyWithTtl(300, $clock);

        $challenge = $ceremony->generateOptions(null)->challenge;

        $clock->advance(seconds: 301);

        try {
            $ceremony->verify($this->assertionFor($challenge), $challenge, 'user-1');
            self::fail('An assertion answering an expired challenge must be refused.');
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

        $challenge = $ceremony->generateOptions(null)->challenge;

        $clock->advance(seconds: 300);

        try {
            $ceremony->verify($this->assertionFor($challenge), $challenge, 'user-1');
            self::fail('The short authenticator data must still be refused.');
        } catch (WebAuthnException $e) {
            self::assertSame('invalid_assertion', $e->errorCode());
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

        try {
            $ceremony->verify($this->assertionFor($challenge), $challenge, 'user-1');
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

        $issuedAt = Challenge::issuedAt($ceremony->generateOptions(null)->challenge);

        self::assertNotNull($issuedAt);
        self::assertSame($clock->timestamp(), $issuedAt->getTimestamp());
    }

    /**
     * The TTL bounds the replay window; it does not close it. Until challenges
     * were consumed server-side, an assertion captured off the wire stayed usable
     * for the whole of `challenge_ttl_seconds` — five minutes by default — while
     * {@see \Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface}
     * documented the ceremony as using "one-time challenges".
     *
     * The clock is advanced by one second between the two attempts, not past the
     * window, and the assertion below states outright that the challenge is still
     * fresh at the second attempt. That is what makes the refusal attributable:
     * `expired_challenge` would mean the window closed, `replayed_challenge` means
     * the window was open and the challenge had already been spent.
     */
    #[Test]
    public function verifyRefusesASecondAssertionAnsweringTheSameChallengeInsideItsTtl(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $ceremony = $this->ceremonyWithTtl(300, $clock);

        $challenge = $ceremony->generateOptions(null)->challenge;

        // First answer. It clears every challenge gate and is refused only for
        // the next reason, so the challenge was genuinely accepted and spent.
        try {
            $ceremony->verify($this->assertionFor($challenge), $challenge, 'user-1');
            self::fail('The short authenticator data must be refused.');
        } catch (WebAuthnException $e) {
            self::assertSame('invalid_assertion', $e->errorCode());
        }

        $clock->advance(seconds: 1);

        self::assertTrue(
            Challenge::isFresh($challenge, 300, $clock->now()),
            'The replay must be attempted while the challenge is still inside its window, '
            . 'or the refusal below proves nothing beyond the TTL that already shipped.',
        );

        try {
            $ceremony->verify($this->assertionFor($challenge), $challenge, 'user-1');
            self::fail('A challenge that was already answered must be refused the second time.');
        } catch (WebAuthnException $e) {
            self::assertSame('replayed_challenge', $e->errorCode());
        }
    }

    /**
     * Single-use must refuse the challenge that was spent, not every challenge
     * after it. A store that answered "already used" to everything would pass the
     * test above while breaking every ceremony the relying party runs.
     */
    #[Test]
    public function spendingOneChallengeLeavesTheNextOneUsable(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $ceremony = $this->ceremonyWithTtl(300, $clock);

        $first = $ceremony->generateOptions(null)->challenge;
        $second = $ceremony->generateOptions(null)->challenge;

        self::assertNotSame($first, $second);

        foreach ([$first, $second] as $challenge) {
            try {
                $ceremony->verify($this->assertionFor($challenge), $challenge, 'user-1');
                self::fail('The short authenticator data must be refused.');
            } catch (WebAuthnException $e) {
                self::assertSame('invalid_assertion', $e->errorCode());
            }
        }
    }

    /**
     * A ceremony holding its own process-local store is single-use only within
     * that process. Two ceremonies standing in for two workers behind a load
     * balancer must therefore be handed the SAME store, and this states the
     * consequence rather than leaving it to the adapter's docblock: with a shared
     * store the replay is refused wherever it lands.
     */
    #[Test]
    public function aSharedStoreRefusesAReplayRoutedToASecondCeremony(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $store = new InMemoryChallengeStore($clock);

        $issuing = $this->ceremonyWithTtl(300, $clock, $store);
        $replayTarget = $this->ceremonyWithTtl(300, $clock, $store);

        $challenge = $issuing->generateOptions(null)->challenge;

        try {
            $issuing->verify($this->assertionFor($challenge), $challenge, 'user-1');
            self::fail('The short authenticator data must be refused.');
        } catch (WebAuthnException $e) {
            self::assertSame('invalid_assertion', $e->errorCode());
        }

        try {
            $replayTarget->verify($this->assertionFor($challenge), $challenge, 'user-1');
            self::fail('A shared store must refuse the replay at the second ceremony too.');
        } catch (WebAuthnException $e) {
            self::assertSame('replayed_challenge', $e->errorCode());
        }
    }

    /**
     * The refusal is auditable. A replayed second factor that leaves no record is
     * a replay nobody can investigate, and the error code is what separates it in
     * the trail from a user who simply took too long.
     */
    #[Test]
    public function aRefusedReplayIsAudited(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');

        $auditLogger = new class implements AuditLoggerInterface {
            /** @var list<mixed> */
            private array $failureCodes = [];

            /**
             * @param array<string, mixed> $metadata
             */
            #[Override]
            public function log(
                AuditEvent $event,
                AuditOutcome $outcome,
                AuditActor|string|null $actor,
                string $action,
                string $resource = '',
                array $metadata = [],
            ): AuditEntry {
                if ($action === 'webauthn.authentication.failed') {
                    $this->failureCodes[] = $metadata['error_code'] ?? null;
                }

                return new AuditEntry(
                    id: 'entry',
                    event: $event,
                    outcome: $outcome,
                    actor: (string) $actor,
                    action: $action,
                    resource: $resource,
                    timestamp: new DateTimeImmutable(),
                    metadata: $metadata,
                    previousHmac: '',
                    hmac: '',
                );
            }

            /**
             * @return list<mixed>
             */
            public function failureCodes(): array
            {
                return $this->failureCodes;
            }
        };

        $ceremony = $this->ceremonyWithTtl(300, $clock, auditLogger: $auditLogger);
        $challenge = $ceremony->generateOptions(null)->challenge;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $ceremony->verify($this->assertionFor($challenge), $challenge, 'user-1');
            } catch (WebAuthnException) {
                // Both attempts fail; the trail they leave is what is under test.
            }
        }

        self::assertSame(['invalid_assertion', 'replayed_challenge'], $auditLogger->failureCodes());
    }

    private function ceremonyWithTtl(
        int $ttlSeconds,
        TestClock $clock,
        ?ChallengeStoreInterface $challengeStore = null,
        ?AuditLoggerInterface $auditLogger = null,
    ): AuthenticationCeremony {
        $credentialRepository = $this->createStub(CredentialRepositoryInterface::class);
        $credentialRepository->method('findById')
            ->willReturn($this->makeCredentialSource('cred-1', 'user-1', []));

        return new AuthenticationCeremony(
            new WebAuthnConfig(
                rpName: 'TestApp',
                rpId: 'example.com',
                origin: 'https://example.com',
                userVerification: 'preferred',
                challengeTtlSeconds: $ttlSeconds,
                timeout: 60000,
            ),
            $credentialRepository,
            $auditLogger ?? $this->auditLogger,
            $clock,
            $challengeStore,
        );
    }

    /**
     * An assertion whose client data is well formed for `$challenge` but whose
     * authenticator data is too short, so a run that clears the challenge gate
     * fails at the very next check and the two outcomes stay distinguishable.
     *
     * @throws JsonException
     */
    private function assertionFor(string $challenge): string
    {
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        return json_encode([
            'rawId' => $this->base64UrlEncode('cred-1'),
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientData),
                'authenticatorData' => $this->base64UrlEncode(str_repeat("\x00", 10)),
                'signature' => $this->base64UrlEncode('sig'),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function freshChallenge(): string
    {
        return Challenge::issue(new DateTimeImmutable());
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
