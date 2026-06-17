<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\AuthEventCollectorInterface;
use Pulsar\Auth\TwoFactor\Confirm2faSetupResult;
use Pulsar\Auth\TwoFactor\InMemoryRecoveryCodeStore;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\InMemoryTotpSecretStore;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeHasher;
use Pulsar\Auth\TwoFactor\RecoveryCodeRotationResult;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Auth\TwoFactor\TwoFactorPurpose;
use Pulsar\Auth\TwoFactor\TwoFactorRateLimiterInterface;
use Pulsar\Auth\TwoFactor\Verify2faResult;
use Pulsar\Auth\TwoFactor\VerifyReason;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Session\SessionInterface;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(TwoFactorManager::class)]
#[CoversClass(Verify2faResult::class)]
#[CoversClass(Confirm2faSetupResult::class)]
#[CoversClass(RecoveryCodeRotationResult::class)]
final class TwoFactorManagerTest extends TestCase
{
    private TwoFactorManager $manager;

    private TotpGenerator $generator;

    private TotpVerifier $verifier;

    private RecoveryCodeGenerator $recoveryCodeGenerator;

    private RecoveryCodeVerifier $recoveryCodeVerifier;

    private InMemoryTotpReplayGuard $replayGuard;

    protected function setUp(): void
    {
        $this->generator = new TotpGenerator();
        $this->verifier = new TotpVerifier($this->generator);
        $this->recoveryCodeGenerator = new RecoveryCodeGenerator();
        $this->recoveryCodeVerifier = new RecoveryCodeVerifier();
        $this->replayGuard = new InMemoryTotpReplayGuard();

        // SEC-2FA-01: verifyCode/verifyCodeWithSecret are fail-closed when no
        // rate limiter is wired. The test exercises the verification path, so
        // wire AllowAllTwoFactorRateLimiter explicitly to make the absence of
        // rate limiting visible in the test (production refuses this binding
        // via TwoFactorRateLimiterReadinessCheck).
        $this->manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            issuer: 'TestApp',
            recoveryCodeCount: 8,
            replayGuard: $this->replayGuard,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );
    }

    #[Test]
    public function beginSetupReturnsArrayWithRequiredKeys(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('displayName')->willReturn('test@example.com');
        $identity->method('id')->willReturn('user-1');

        $result = $this->manager->beginSetup($identity);

        self::assertArrayHasKey('secret', $result);
        self::assertArrayHasKey('secret_base32', $result);
        self::assertArrayHasKey('provisioning_uri', $result);
        self::assertArrayHasKey('recovery_codes', $result);

        self::assertIsString($result['secret']);
        self::assertNotEmpty($result['secret']);

        self::assertIsString($result['secret_base32']);
        self::assertNotEmpty($result['secret_base32']);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $result['secret_base32']);

        self::assertStringStartsWith('otpauth://totp/', $result['provisioning_uri']);

        self::assertIsArray($result['recovery_codes']);
        self::assertCount(8, $result['recovery_codes']);
    }

    #[Test]
    public function confirmSetupDelegatesToVerifier(): void
    {
        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $result = $this->manager->confirmSetup('user-1', $secret, $code);

        self::assertInstanceOf(Confirm2faSetupResult::class, $result);
        self::assertTrue($result->confirmed);
        self::assertSame(VerifyReason::Valid, $result->reason);
    }

    #[Test]
    public function confirmSetupRejectsInvalidCode(): void
    {
        $secret = $this->generator->generateSecret();

        $result = $this->manager->confirmSetup('user-1', $secret, '000000');

        self::assertFalse($result->confirmed);
        self::assertSame(VerifyReason::InvalidCode, $result->reason);
    }

    #[Test]
    public function verifyCodeWithSecretReturnsTypedResult(): void
    {
        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $result = $this->manager->verifyCodeWithSecret('user-1', $secret, $code);

        self::assertInstanceOf(Verify2faResult::class, $result);
        self::assertTrue($result->verified);
        self::assertSame(VerifyReason::Valid, $result->reason);
        self::assertSame(TwoFactorPurpose::Login, $result->purpose);
        self::assertNotNull($result->acceptedTimeStep);
    }

    #[Test]
    public function verifyCodeWithSecretRejectsInvalidCode(): void
    {
        $secret = $this->generator->generateSecret();

        $result = $this->manager->verifyCodeWithSecret('user-1', $secret, '000000');

        self::assertFalse($result->verified);
        self::assertSame(VerifyReason::InvalidCode, $result->reason);
        self::assertNull($result->acceptedTimeStep);
    }

    #[Test]
    public function verifyCodeWithSecretRejectsReplayedCode(): void
    {
        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $first = $this->manager->verifyCodeWithSecret('user-1', $secret, $code);
        self::assertTrue($first->verified);

        $second = $this->manager->verifyCodeWithSecret('user-1', $secret, $code);
        self::assertFalse($second->verified);
        self::assertSame(VerifyReason::InvalidCode, $second->reason);
    }

    #[Test]
    public function verifyCodeWithoutStoreReturnsNotEnrolled(): void
    {
        $result = $this->manager->verifyCode('user-1', '123456');

        self::assertFalse($result->verified);
        self::assertSame(VerifyReason::NotEnrolled, $result->reason);
    }

    #[Test]
    public function verifyCodeWithSecretAcceptsPurpose(): void
    {
        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $result = $this->manager->verifyCodeWithSecret('user-1', $secret, $code, TwoFactorPurpose::StepUp);

        self::assertTrue($result->verified);
        self::assertSame(TwoFactorPurpose::StepUp, $result->purpose);
    }

    #[Test]
    public function verifyRecoveryCodeDelegatesToRecoveryCodeVerifier(): void
    {
        $validCodes = ['ABCD-1234', 'EF56-7890'];

        self::assertSame(0, $this->manager->verifyRecoveryCode('user-1', 'ABCD-1234', $validCodes));
        self::assertSame(1, $this->manager->verifyRecoveryCode('user-1', 'EF56-7890', $validCodes));
        self::assertSame(-1, $this->manager->verifyRecoveryCode('user-1', 'FFFF-FFFF', $validCodes));
    }

    #[Test]
    public function verifyCodeWithStoreLoadsSecretAutomatically(): void
    {
        $secretStore = new InMemoryTotpSecretStore();
        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            replayGuard: $this->replayGuard,
            secretStore: $secretStore,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $secret = $this->generator->generateSecret();
        $secretStore->store('user-1', $secret);
        $code = $this->generator->computeCode($secret);

        $result = $manager->verifyCode('user-1', $code);

        self::assertTrue($result->verified);
        self::assertSame(VerifyReason::Valid, $result->reason);
    }

    #[Test]
    public function verifyCodeWithStoreReturnsNotEnrolledWhenNoSecret(): void
    {
        $secretStore = new InMemoryTotpSecretStore();
        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            secretStore: $secretStore,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->verifyCode('user-1', '123456');

        self::assertFalse($result->verified);
        self::assertSame(VerifyReason::NotEnrolled, $result->reason);
    }

    #[Test]
    public function confirmSetupStoresSecretInStore(): void
    {
        $secretStore = new InMemoryTotpSecretStore();
        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            secretStore: $secretStore,
            replayGuard: $this->replayGuard,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $result = $manager->confirmSetup('user-1', $secret, $code);

        self::assertTrue($result->confirmed);
        self::assertSame($secret, $secretStore->retrieve('user-1'));
    }

    #[Test]
    public function auditLoggerReceivesEventsOnVerify(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authentication,
                AuditOutcome::Success,
                'user-1',
                '2fa_code_verified',
                self::anything(),
                self::callback(static fn(array $metadata): bool => isset($metadata['purpose'])
                    && $metadata['purpose'] === 'login'),
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            replayGuard: $this->replayGuard,
            auditLogger: $auditLogger,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $manager->verifyCodeWithSecret('user-1', $secret, $code);
    }

    #[Test]
    public function auditLoggerReceivesFailureOnInvalidCode(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authentication,
                AuditOutcome::Failure,
                'user-1',
                '2fa_code_verification_failed',
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            replayGuard: $this->replayGuard,
            auditLogger: $auditLogger,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $manager->verifyCodeWithSecret('user-1', $this->generator->generateSecret(), '000000');
    }

    #[Test]
    public function eventCollectorReceivesEventsOnVerify(): void
    {
        $collector = $this->createMock(AuthEventCollectorInterface::class);
        $collector->expects(self::once())
            ->method('recordTwoFactorEvent')
            ->with(
                '2fa_code_verified',
                'success',
                'user-1',
                self::callback(static fn(array $metadata): bool => isset($metadata['purpose'])
                    && $metadata['purpose'] === 'login'),
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            replayGuard: $this->replayGuard,
            eventCollector: $collector,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $manager->verifyCodeWithSecret('user-1', $secret, $code);
    }

    #[Test]
    public function eventCollectorMetadataNeverContainsSensitiveKeys(): void
    {
        $collector = $this->createMock(AuthEventCollectorInterface::class);
        $collector->expects(self::atLeastOnce())
            ->method('recordTwoFactorEvent')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    $forbidden = ['secret', 'code', 'token', 'password', 'key'];
                    foreach ($forbidden as $key) {
                        if (isset($metadata[$key])) {
                            return false;
                        }
                    }

                    return true;
                }),
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            replayGuard: $this->replayGuard,
            eventCollector: $collector,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);
        $manager->verifyCodeWithSecret('user-1', $secret, $code);
    }

    #[Test]
    public function rateLimiterBlocksVerification(): void
    {
        $rateLimiter = $this->createStub(TwoFactorRateLimiterInterface::class);
        $rateLimiter->method('attempt')->willReturn(false);

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            rateLimiter: $rateLimiter,
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $result = $manager->verifyCodeWithSecret('user-1', $secret, $code);

        self::assertFalse($result->verified);
        self::assertSame(VerifyReason::RateLimited, $result->reason);
    }

    #[Test]
    public function sessionRegeneratedOnSuccessfulVerify(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('regenerate');

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            replayGuard: $this->replayGuard,
            session: $session,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $manager->verifyCodeWithSecret('user-1', $secret, $code);
    }

    #[Test]
    public function sessionNotRegeneratedOnFailedVerify(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::never())->method('regenerate');

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            session: $session,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $manager->verifyCodeWithSecret('user-1', $this->generator->generateSecret(), '000000');
    }

    #[Test]
    public function rotateRecoveryCodesGeneratesNewSet(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $hasherKey = $masterKey->deriveSubKey(3, 'rcvrycod');
        $hasher = new RecoveryCodeHasher($hasherKey);
        $store = new InMemoryRecoveryCodeStore();

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            recoveryCodeHasher: $hasher,
            recoveryCodeStore: $store,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->rotateRecoveryCodes('user-1');

        self::assertInstanceOf(RecoveryCodeRotationResult::class, $result);
        self::assertCount(8, $result->plaintextCodes);
        self::assertCount(8, $result->set->codeHashes);
        self::assertSame([], $result->set->usedIndices);
        self::assertSame(2, $result->set->algorithmVersion);

        // Verify stored in store
        $loaded = $store->loadSet('user-1');
        self::assertNotNull($loaded);
        self::assertSame($result->set->setId, $loaded->setId);
    }

    #[Test]
    public function rotatedRecoveryCodesCanBeConsumed(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $hasherKey = $masterKey->deriveSubKey(3, 'rcvrycod');
        $hasher = new RecoveryCodeHasher($hasherKey);
        $store = new InMemoryRecoveryCodeStore();

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            recoveryCodeHasher: $hasher,
            recoveryCodeStore: $store,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->rotateRecoveryCodes('user-1');
        $firstCode = $result->plaintextCodes[0];

        // Verify the code can be consumed
        $index = $manager->verifyRecoveryCode('user-1', $firstCode);
        self::assertSame(0, $index);

        // Second attempt for same code should fail (atomic consume)
        $secondAttempt = $manager->verifyRecoveryCode('user-1', $firstCode);
        self::assertSame(-1, $secondAttempt);
    }

    #[Test]
    public function verifyRecoveryCodeWithStoreRejectsUnknownCode(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $hasherKey = $masterKey->deriveSubKey(3, 'rcvrycod');
        $hasher = new RecoveryCodeHasher($hasherKey);
        $store = new InMemoryRecoveryCodeStore();

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            recoveryCodeHasher: $hasher,
            recoveryCodeStore: $store,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $manager->rotateRecoveryCodes('user-1');

        $index = $manager->verifyRecoveryCode('user-1', 'FFFF-FFFF-FFFF-FFFF');
        self::assertSame(-1, $index);
    }

    #[Test]
    public function sessionRegeneratedOnRecoveryCodeUse(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('regenerate');

        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $hasherKey = $masterKey->deriveSubKey(3, 'rcvrycod');
        $hasher = new RecoveryCodeHasher($hasherKey);
        $store = new InMemoryRecoveryCodeStore();

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            recoveryCodeHasher: $hasher,
            recoveryCodeStore: $store,
            session: $session,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->rotateRecoveryCodes('user-1');
        $manager->verifyRecoveryCode('user-1', $result->plaintextCodes[0]);
    }

    #[Test]
    public function verifyCodeWithStoreRateLimited(): void
    {
        $rateLimiter = $this->createStub(TwoFactorRateLimiterInterface::class);
        $rateLimiter->method('attempt')->willReturn(false);

        $secretStore = new InMemoryTotpSecretStore();
        $secretStore->store('user-1', $this->generator->generateSecret());

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            rateLimiter: $rateLimiter,
            secretStore: $secretStore,
        );

        $result = $manager->verifyCode('user-1', '123456');

        self::assertFalse($result->verified);
        self::assertSame(VerifyReason::RateLimited, $result->reason);
    }

    #[Test]
    public function verifyCodeWithStoreRateLimitedAuditsEvent(): void
    {
        $rateLimiter = $this->createStub(TwoFactorRateLimiterInterface::class);
        $rateLimiter->method('attempt')->willReturn(false);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authentication,
                AuditOutcome::Denied,
                'user-1',
                '2fa_code_verification_failed',
            );

        $secretStore = new InMemoryTotpSecretStore();

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            rateLimiter: $rateLimiter,
            secretStore: $secretStore,
            auditLogger: $auditLogger,
        );

        $manager->verifyCode('user-1', '123456');
    }

    #[Test]
    public function verifyCodeWithSecretRateLimitedAuditsEvent(): void
    {
        $rateLimiter = $this->createStub(TwoFactorRateLimiterInterface::class);
        $rateLimiter->method('attempt')->willReturn(false);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authentication,
                AuditOutcome::Denied,
                'user-1',
                '2fa_code_verification_failed',
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            rateLimiter: $rateLimiter,
            auditLogger: $auditLogger,
        );

        $manager->verifyCodeWithSecret('user-1', 'any-secret', '123456');
    }

    #[Test]
    public function recoveryCodeStoreBackedReplayAuditsReplayedAction(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $hasherKey = $masterKey->deriveSubKey(3, 'rcvrycod');
        $hasher = new RecoveryCodeHasher($hasherKey);
        $store = new InMemoryRecoveryCodeStore();

        /** @var list<array{AuditEvent, AuditOutcome, string, string}> $auditCalls */
        $auditCalls = [];
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturnCallback(
            function (AuditEvent $event, AuditOutcome $outcome, ?string $actor, string $action) use (&$auditCalls): AuditEntry {
                $auditCalls[] = [$event, $outcome, $actor ?? 'unknown', $action];

                return new AuditEntry(
                    id: 'test',
                    event: $event,
                    outcome: $outcome,
                    actor: $actor ?? 'unknown',
                    action: $action,
                    resource: '',
                    timestamp: new DateTimeImmutable(),
                    metadata: [],
                    previousHmac: '',
                    hmac: '',
                    kid: '',
                );
            },
        );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            recoveryCodeHasher: $hasher,
            recoveryCodeStore: $store,
            auditLogger: $auditLogger,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->rotateRecoveryCodes('user-1');
        $firstCode = $result->plaintextCodes[0];

        // First use: success
        $manager->verifyRecoveryCode('user-1', $firstCode);

        // Second use: replay (already consumed)
        $index = $manager->verifyRecoveryCode('user-1', $firstCode);
        self::assertSame(-1, $index);

        // Find the replay audit event
        $replayEvents = array_filter(
            $auditCalls,
            static fn(array $call): bool => $call[3] === '2fa_recovery_code_replayed',
        );

        self::assertNotEmpty($replayEvents, 'Expected a replay audit event');
        $replayEvent = array_values($replayEvents)[0];
        self::assertSame(AuditOutcome::Denied, $replayEvent[1]);
    }

    #[Test]
    public function recoveryCodeStoreBackedFailureAuditsFailedAction(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $hasherKey = $masterKey->deriveSubKey(3, 'rcvrycod');
        $hasher = new RecoveryCodeHasher($hasherKey);
        $store = new InMemoryRecoveryCodeStore();

        /** @var list<array{AuditEvent, AuditOutcome, string, string}> $auditCalls */
        $auditCalls = [];
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturnCallback(
            function (AuditEvent $event, AuditOutcome $outcome, ?string $actor, string $action) use (&$auditCalls): AuditEntry {
                $auditCalls[] = [$event, $outcome, $actor ?? 'unknown', $action];

                return new AuditEntry(
                    id: 'test',
                    event: $event,
                    outcome: $outcome,
                    actor: $actor ?? 'unknown',
                    action: $action,
                    resource: '',
                    timestamp: new DateTimeImmutable(),
                    metadata: [],
                    previousHmac: '',
                    hmac: '',
                    kid: '',
                );
            },
        );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            recoveryCodeHasher: $hasher,
            recoveryCodeStore: $store,
            auditLogger: $auditLogger,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $manager->rotateRecoveryCodes('user-1');

        // Try a completely wrong code
        $index = $manager->verifyRecoveryCode('user-1', 'ZZZZ-ZZZZ-ZZZZ-ZZZZ');
        self::assertSame(-1, $index);

        $failedEvents = array_filter(
            $auditCalls,
            static fn(array $call): bool => $call[3] === '2fa_recovery_code_failed',
        );

        self::assertNotEmpty($failedEvents, 'Expected a failed recovery code audit event');
        $failedEvent = array_values($failedEvents)[0];
        self::assertSame(AuditOutcome::Failure, $failedEvent[1]);
    }

    #[Test]
    public function recoveryCodeSuccessAuditsAndDispatchesCollectorEvent(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $hasherKey = $masterKey->deriveSubKey(3, 'rcvrycod');
        $hasher = new RecoveryCodeHasher($hasherKey);
        $store = new InMemoryRecoveryCodeStore();

        $collector = $this->createMock(AuthEventCollectorInterface::class);
        $collector->expects(self::atLeastOnce())
            ->method('recordTwoFactorEvent')
            ->with(
                self::anything(),
                self::anything(),
                'user-1',
                self::anything(),
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            recoveryCodeHasher: $hasher,
            recoveryCodeStore: $store,
            eventCollector: $collector,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->rotateRecoveryCodes('user-1');
        $manager->verifyRecoveryCode('user-1', $result->plaintextCodes[0]);
    }

    #[Test]
    public function rotateRecoveryCodesWithoutHasherProducesEmptyHashes(): void
    {
        $store = new InMemoryRecoveryCodeStore();

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            recoveryCodeStore: $store,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->rotateRecoveryCodes('user-1');

        self::assertCount(8, $result->plaintextCodes);
        self::assertSame([], $result->set->codeHashes);
    }

    #[Test]
    public function rotateRecoveryCodesAuditsEvent(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Success,
                'user-1',
                '2fa_recovery_codes_rotated',
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            auditLogger: $auditLogger,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $manager->rotateRecoveryCodes('user-1');
    }

    #[Test]
    public function verifyCodeWithStoreAndInvalidCodeAuditsFailure(): void
    {
        $secretStore = new InMemoryTotpSecretStore();
        $secret = $this->generator->generateSecret();
        $secretStore->store('user-1', $secret);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authentication,
                AuditOutcome::Failure,
                'user-1',
                '2fa_code_verification_failed',
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            secretStore: $secretStore,
            auditLogger: $auditLogger,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->verifyCode('user-1', '000000');

        self::assertFalse($result->verified);
        self::assertSame(VerifyReason::InvalidCode, $result->reason);
    }

    #[Test]
    public function verifyCodeWithDifferentPurpose(): void
    {
        $secretStore = new InMemoryTotpSecretStore();
        $secret = $this->generator->generateSecret();
        $secretStore->store('user-1', $secret);
        $code = $this->generator->computeCode($secret);

        $replayGuard = new InMemoryTotpReplayGuard();

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            replayGuard: $replayGuard,
            secretStore: $secretStore,
            rateLimiter: new \Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter(),
        );

        $result = $manager->verifyCode('user-1', $code, TwoFactorPurpose::StepUp);

        self::assertTrue($result->verified);
        self::assertSame(TwoFactorPurpose::StepUp, $result->purpose);
    }

    #[Test]
    public function confirmSetupRateLimitedReturnsFailure(): void
    {
        $rateLimiter = $this->createStub(TwoFactorRateLimiterInterface::class);
        $rateLimiter->method('attempt')->willReturn(false);

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            rateLimiter: $rateLimiter,
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $result = $manager->confirmSetup('user-1', $secret, $code);

        self::assertFalse($result->confirmed);
        self::assertSame(VerifyReason::RateLimited, $result->reason);
    }

    #[Test]
    public function confirmSetupRateLimitedAuditsEvent(): void
    {
        $rateLimiter = $this->createStub(TwoFactorRateLimiterInterface::class);
        $rateLimiter->method('attempt')->willReturn(false);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Denied,
                'user-1',
                '2fa_setup_confirmation_failed',
            );

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            rateLimiter: $rateLimiter,
            auditLogger: $auditLogger,
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $manager->confirmSetup('user-1', $secret, $code);
    }

    #[Test]
    public function confirmSetupUsesReplayGuardWhenRateLimiterPasses(): void
    {
        $rateLimiter = $this->createStub(TwoFactorRateLimiterInterface::class);
        $rateLimiter->method('attempt')->willReturn(true);

        $manager = new TwoFactorManager(
            generator: $this->generator,
            verifier: $this->verifier,
            recoveryCodeGenerator: $this->recoveryCodeGenerator,
            recoveryCodeVerifier: $this->recoveryCodeVerifier,
            rateLimiter: $rateLimiter,
            replayGuard: $this->replayGuard,
        );

        $secret = $this->generator->generateSecret();
        $code = $this->generator->computeCode($secret);

        $result = $manager->confirmSetup('user-1', $secret, $code);
        self::assertTrue($result->confirmed);

        // Replay should fail (replay guard is active)
        $result2 = $manager->confirmSetup('user-1', $secret, $code);
        self::assertFalse($result2->confirmed);
    }
}
