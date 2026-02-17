<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\Confirm2faSetupResult;
use Pulsar\Auth\TwoFactor\TwoFactorManagerInterface;
use Pulsar\Auth\TwoFactor\VerifyReason;
use Pulsar\Live\Auth\AuthUiConfig;
use Pulsar\Live\Auth\MfaEnrollment;

#[CoversClass(MfaEnrollment::class)]
final class MfaEnrollmentTest extends TestCase
{
    #[Test]
    public function mountInitializesSetupStep(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('user-42');

        $manager = $this->createStub(TwoFactorManagerInterface::class);
        $manager->method('beginSetup')->willReturn([
            'provisioning_uri' => 'otpauth://totp/Pulsar:user@example.com?secret=ABC',
            'secret_base32' => 'ABC123',
            'secret' => 'raw-secret',
            'recovery_codes' => ['code-1', 'code-2'],
        ]);

        $component = new MfaEnrollment();
        $component->mount([
            'twoFactorManager' => $manager,
            'identity' => $identity,
        ]);

        self::assertSame('otpauth://totp/Pulsar:user@example.com?secret=ABC', $component->provisioningUri);
        self::assertSame('ABC123', $component->secretBase32);
        self::assertSame('raw-secret', $component->secret);
        self::assertSame(['code-1', 'code-2'], $component->recoveryCodes);
        self::assertSame('user-42', $component->identityId);
    }

    #[Test]
    public function mountWithoutManagerSkipsSetup(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);

        self::assertSame('', $component->provisioningUri);
        self::assertSame('setup', $component->step);
    }

    #[Test]
    public function confirmSetupSetsErrorWhenCodeEmpty(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);
        $component->verificationCode = '';

        $component->confirmSetup();

        self::assertSame('Please enter the 6-digit code from your authenticator app.', $component->error);
    }

    #[Test]
    public function confirmSetupSetsErrorWhenManagerUnavailable(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);
        $component->verificationCode = '123456';

        $component->confirmSetup();

        self::assertSame('Two-factor service unavailable.', $component->error);
    }

    #[Test]
    public function confirmSetupWithInvalidCodeShowsError(): void
    {
        $result = Confirm2faSetupResult::failure(VerifyReason::InvalidCode);

        $manager = $this->createStub(TwoFactorManagerInterface::class);
        $manager->method('beginSetup')->willReturn([
            'provisioning_uri' => 'otpauth://totp/test',
            'secret_base32' => 'ABC',
            'secret' => 'sec',
            'recovery_codes' => [],
        ]);
        $manager->method('confirmSetup')->willReturn($result);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('u1');

        $component = new MfaEnrollment();
        $component->mount(['twoFactorManager' => $manager, 'identity' => $identity]);
        $component->verificationCode = '000000';

        $component->confirmSetup();

        self::assertSame('Invalid code. Please try again with a new code from your app.', $component->error);
        self::assertSame('', $component->verificationCode);
        self::assertSame('setup', $component->step);
    }

    #[Test]
    public function confirmSetupSuccessTransitionsToRecoveryStep(): void
    {
        $result = Confirm2faSetupResult::success();

        $manager = $this->createStub(TwoFactorManagerInterface::class);
        $manager->method('beginSetup')->willReturn([
            'provisioning_uri' => 'otpauth://totp/test',
            'secret_base32' => 'ABC',
            'secret' => 'sec',
            'recovery_codes' => ['r1', 'r2'],
        ]);
        $manager->method('confirmSetup')->willReturn($result);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('u1');

        $component = new MfaEnrollment();
        $component->mount(['twoFactorManager' => $manager, 'identity' => $identity]);
        $component->verificationCode = '123456';

        $component->confirmSetup();

        self::assertSame('recovery', $component->step);
        self::assertSame('', $component->error);
    }

    #[Test]
    public function completeTransitionsToCompleteStep(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);

        $component->complete();

        self::assertSame('complete', $component->step);
    }

    #[Test]
    public function renderSetupStepContainsQrSection(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);
        $component->provisioningUri = 'otpauth://test';
        $component->secretBase32 = 'SECRET';

        $html = $component->render();

        self::assertStringContainsString('Set Up Two-Factor Authentication', $html);
        self::assertStringContainsString('data-provisioning-uri', $html);
        self::assertStringContainsString('SECRET', $html);
        self::assertStringContainsString('wire:submit="confirmSetup"', $html);
    }

    #[Test]
    public function renderRecoveryStepShowsRecoveryCodes(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);
        $component->step = 'recovery';
        $component->recoveryCodes = ['aaa-bbb', 'ccc-ddd'];

        $html = $component->render();

        self::assertStringContainsString('Save Your Recovery Codes', $html);
        self::assertStringContainsString('aaa-bbb', $html);
        self::assertStringContainsString('ccc-ddd', $html);
        self::assertStringContainsString('wire:click="complete"', $html);
    }

    #[Test]
    public function renderCompleteStepShowsSuccessMessage(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);
        $component->step = 'complete';

        $html = $component->render();

        self::assertStringContainsString('Two-Factor Authentication Enabled', $html);
    }

    #[Test]
    public function renderSetupStepWithErrorShowsAlert(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);
        $component->error = 'Something went wrong';

        $html = $component->render();

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('Something went wrong', $html);
    }

    #[Test]
    public function renderWithDarkModeAddsClass(): void
    {
        $component = new MfaEnrollment();
        $component->mount(['config' => new AuthUiConfig(darkMode: true)]);

        $html = $component->render();

        self::assertStringContainsString('pulsar-auth--dark', $html);
    }

    #[Test]
    public function renderWithoutDarkModeHasNoDarkClass(): void
    {
        $component = new MfaEnrollment();
        $component->mount([]);

        $html = $component->render();

        self::assertStringNotContainsString('pulsar-auth--dark', $html);
    }
}
