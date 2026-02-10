<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\InMemoryTotpSecretStore;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Auth\TwoFactor\TwoFactorPurpose;
use Pulsar\Auth\TwoFactor\VerifyReason;

use function in_array;

/**
 * E2E: Admin 2FA workflow — enable -> confirm setup -> login with TOTP -> step-up -> recovery code.
 */
#[CoversClass(TwoFactorManager::class)]
#[Group('e2e-cms')]
final class AdminTwoFactorTest extends TestCase
{
    #[Test]
    public function full2faLifecycle(): void
    {
        $generator = new TotpGenerator(codeDigits: 6, period: 30);
        $verifier = new TotpVerifier($generator, window: 1);
        $recoveryCodeGenerator = new RecoveryCodeGenerator();
        $recoveryCodeVerifier = new RecoveryCodeVerifier();
        $secretStore = new InMemoryTotpSecretStore();
        $replayGuard = new InMemoryTotpReplayGuard();

        $manager = new TwoFactorManager(
            generator: $generator,
            verifier: $verifier,
            recoveryCodeGenerator: $recoveryCodeGenerator,
            recoveryCodeVerifier: $recoveryCodeVerifier,
            issuer: 'Pulsar CMS',
            recoveryCodeCount: 8,
            replayGuard: $replayGuard,
            secretStore: $secretStore,
        );

        $identity = new E2EAdminIdentity(
            id: 'admin-2fa-001',
            displayName: 'CMS Admin',
            roles: ['admin', 'editor'],
        );

        // Step 1: Begin setup — get secret + provisioning URI + recovery codes
        $setup = $manager->beginSetup($identity);

        self::assertNotEmpty($setup['secret']);
        self::assertNotEmpty($setup['secret_base32']);
        self::assertStringStartsWith('otpauth://totp/', $setup['provisioning_uri']);
        self::assertStringContainsString('Pulsar%20CMS', $setup['provisioning_uri']);
        self::assertCount(8, $setup['recovery_codes']);

        $secret = $setup['secret'];
        $recoveryCodes = $setup['recovery_codes'];

        // Step 2: Generate a valid TOTP code and confirm setup
        $validCode = $generator->computeCode($secret);
        $confirmResult = $manager->confirmSetup($identity->id(), $secret, $validCode);

        self::assertTrue($confirmResult->confirmed);
        self::assertSame(VerifyReason::Valid, $confirmResult->reason);

        // Step 3: Verify secret was stored
        $storedSecret = $secretStore->retrieve($identity->id());
        self::assertSame($secret, $storedSecret);

        // Step 4: Login with TOTP — compute fresh code
        $loginCode = $generator->computeCode($secret);
        $loginResult = $manager->verifyCode(
            $identity->id(),
            $loginCode,
            TwoFactorPurpose::Login,
        );

        self::assertTrue($loginResult->verified);
        self::assertSame(VerifyReason::Valid, $loginResult->reason);
        self::assertSame(TwoFactorPurpose::Login, $loginResult->purpose);
        self::assertNotNull($loginResult->acceptedTimeStep);

        // Step 5: Replay same code — must be rejected
        $replayResult = $manager->verifyCode(
            $identity->id(),
            $loginCode,
            TwoFactorPurpose::Login,
        );

        self::assertFalse($replayResult->verified);
        self::assertSame(VerifyReason::InvalidCode, $replayResult->reason);

        // Step 6: Step-up verification with a fresh code
        // Compute code for the next TOTP period (step N+1) which is always
        // within window=1. Using an explicit step avoids a flaky failure that
        // occurs when time() falls on the last second of a period and a naive
        // offset like +31 lands in step N+2 (outside the window).
        $currentStep = intdiv(time(), 30);
        $nextStepTimestamp = ($currentStep + 1) * 30;
        $stepUpCode = $generator->computeCode($secret, $nextStepTimestamp);
        $stepUpResult = $manager->verifyCodeWithSecret(
            $identity->id(),
            $secret,
            $stepUpCode,
            TwoFactorPurpose::StepUp,
        );

        self::assertTrue($stepUpResult->verified);
        self::assertSame(TwoFactorPurpose::StepUp, $stepUpResult->purpose);

        // Step 7: Use recovery code (legacy mode)
        $recoveryIndex = $manager->verifyRecoveryCode(
            $identity->id(),
            $recoveryCodes[0],
            $recoveryCodes,
        );

        self::assertSame(0, $recoveryIndex);

        // Step 8: Invalid recovery code rejected
        $invalidIndex = $manager->verifyRecoveryCode(
            $identity->id(),
            'INVALID-CODE-HERE',
            $recoveryCodes,
        );

        self::assertSame(-1, $invalidIndex);
    }

    #[Test]
    public function invalidTotpCodeRejectedDuringSetup(): void
    {
        $generator = new TotpGenerator();
        $verifier = new TotpVerifier($generator);
        $recoveryCodeGenerator = new RecoveryCodeGenerator();
        $recoveryCodeVerifier = new RecoveryCodeVerifier();
        $secretStore = new InMemoryTotpSecretStore();

        $manager = new TwoFactorManager(
            generator: $generator,
            verifier: $verifier,
            recoveryCodeGenerator: $recoveryCodeGenerator,
            recoveryCodeVerifier: $recoveryCodeVerifier,
            secretStore: $secretStore,
        );

        $identity = new E2EAdminIdentity(
            id: 'admin-2fa-002',
            displayName: 'Editor',
            roles: ['editor'],
        );

        $setup = $manager->beginSetup($identity);

        $result = $manager->confirmSetup($identity->id(), $setup['secret'], '000000');

        self::assertFalse($result->confirmed);
        self::assertSame(VerifyReason::InvalidCode, $result->reason);
    }

    #[Test]
    public function verifyCodeForNonEnrolledUser(): void
    {
        $generator = new TotpGenerator();
        $verifier = new TotpVerifier($generator);
        $recoveryCodeGenerator = new RecoveryCodeGenerator();
        $recoveryCodeVerifier = new RecoveryCodeVerifier();
        $secretStore = new InMemoryTotpSecretStore();

        $manager = new TwoFactorManager(
            generator: $generator,
            verifier: $verifier,
            recoveryCodeGenerator: $recoveryCodeGenerator,
            recoveryCodeVerifier: $recoveryCodeVerifier,
            secretStore: $secretStore,
        );

        $result = $manager->verifyCode('non-enrolled-user', '123456');

        self::assertFalse($result->verified);
        self::assertSame(VerifyReason::NotEnrolled, $result->reason);
    }

    #[Test]
    public function recoveryCodeFormat(): void
    {
        $generator = new RecoveryCodeGenerator();
        $codes = $generator->generate(8);

        self::assertCount(8, $codes);

        foreach ($codes as $code) {
            // XXXX-XXXX-XXXX-XXXX format
            self::assertMatchesRegularExpression('/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $code);
        }

        // All codes must be unique
        self::assertCount(8, array_unique($codes));
    }
}

/**
 * @internal Stub identity for 2FA E2E tests.
 */
final readonly class E2EAdminIdentity implements IdentityInterface
{
    /**
     * @param non-empty-string $id
     * @param list<string> $roles
     */
    public function __construct(
        private string $id,
        private string $displayName,
        private array $roles = [],
    ) {}

    /** @return non-empty-string */
    public function id(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function twoFactorStatus(): TwoFactorStatus
    {
        return TwoFactorStatus::Verified;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return [];
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
