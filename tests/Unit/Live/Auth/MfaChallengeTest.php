<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Auth\AuthenticatorInterface;
use Pulsar\Live\Auth\AuthResult;
use Pulsar\Live\Auth\AuthUiConfig;
use Pulsar\Live\Auth\MfaChallenge;

#[CoversClass(MfaChallenge::class)]
final class MfaChallengeTest extends TestCase
{
    #[Test]
    public function renderShowsTotpForm(): void
    {
        $component = new MfaChallenge();
        $component->mount(['identityId' => 'user-1', 'config' => new AuthUiConfig()]);

        $html = $component->render();

        self::assertStringContainsString('Two-Factor Authentication', $html);
        self::assertStringContainsString('6-digit code', $html);
        self::assertStringContainsString('wire:model="code"', $html);
        self::assertStringContainsString('inputmode="numeric"', $html);
    }

    #[Test]
    public function toggleRecoveryModeSwitchesView(): void
    {
        $component = new MfaChallenge();
        $component->mount(['identityId' => 'user-1', 'config' => new AuthUiConfig()]);

        $component->toggleRecoveryMode();

        self::assertTrue($component->useRecoveryCode);

        $html = $component->render();
        self::assertStringContainsString('Recovery Code', $html);
        self::assertStringContainsString('Use authenticator app instead', $html);
    }

    #[Test]
    public function toggleRecoveryModeClearsState(): void
    {
        $component = new MfaChallenge();
        $component->mount(['identityId' => 'user-1', 'config' => new AuthUiConfig()]);
        $component->code = '123456';
        $component->error = 'some error';

        $component->toggleRecoveryMode();

        self::assertSame('', $component->code);
        self::assertSame('', $component->error);
    }

    #[Test]
    public function verifyWithEmptyCodeSetsError(): void
    {
        $component = new MfaChallenge();
        $component->mount(['identityId' => 'user-1', 'config' => new AuthUiConfig()]);
        $component->code = '';

        $component->verify();

        self::assertStringContainsString('6-digit code', $component->error);
    }

    #[Test]
    public function verifyWithEmptyRecoveryCodeSetsError(): void
    {
        $component = new MfaChallenge();
        $component->mount(['identityId' => 'user-1', 'config' => new AuthUiConfig()]);
        $component->useRecoveryCode = true;
        $component->code = '';

        $component->verify();

        self::assertStringContainsString('recovery code', $component->error);
    }

    #[Test]
    public function verifyWithoutAuthenticatorSetsError(): void
    {
        $component = new MfaChallenge();
        $component->mount(['identityId' => 'user-1', 'config' => new AuthUiConfig()]);
        $component->code = '123456';

        $component->verify();

        self::assertSame('Authentication service unavailable.', $component->error);
    }

    #[Test]
    public function verifyWithInvalidCode(): void
    {
        $auth = $this->createStub(AuthenticatorInterface::class);
        $auth->method('verifyMfa')->willReturn(AuthResult::failed('Invalid code'));

        $component = new MfaChallenge();
        $component->mount(['identityId' => 'user-1', 'config' => new AuthUiConfig(), 'authenticator' => $auth]);
        $component->code = '000000';

        $component->verify();

        self::assertSame('Invalid code', $component->error);
        self::assertSame('', $component->code);
    }

    #[Test]
    public function verifySuccessful(): void
    {
        $auth = $this->createStub(AuthenticatorInterface::class);
        $auth->method('verifyMfa')->willReturn(AuthResult::ok('/dashboard'));

        $component = new MfaChallenge();
        $component->mount(['identityId' => 'user-1', 'config' => new AuthUiConfig(), 'authenticator' => $auth]);
        $component->code = '123456';

        $component->verify();

        self::assertSame('', $component->error);
    }

    #[Test]
    public function mountAcceptsHyphenatedParam(): void
    {
        $component = new MfaChallenge();
        $component->mount(['identity-id' => 'user-99']);

        self::assertSame('user-99', $component->identityId);
    }
}
