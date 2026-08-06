<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Live\Auth\AuthenticatorInterface;
use Pulsar\Live\Auth\AuthResult;
use Pulsar\Live\Auth\AuthUiConfig;
use Pulsar\Live\Auth\SignupPage;

#[CoversClass(SignupPage::class)]
final class SignupPageTest extends TestCase
{
    #[Test]
    public function renderContainsSignupForm(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig()]);

        $html = $page->render();

        self::assertStringContainsString('Create your account', $html);
        self::assertStringContainsString('wire:submit="submit"', $html);
        self::assertStringContainsString('wire:model="name"', $html);
        self::assertStringContainsString('wire:model="email"', $html);
        self::assertStringContainsString('wire:model="password"', $html);
        self::assertStringContainsString('wire:model="passwordConfirmation"', $html);
    }

    /**
     * The rendered number used to come from configuration while the server
     * enforced a compiled-in 8, inside a form marked `novalidate` so the
     * `minlength` attribute never fired either.
     */
    #[Test]
    public function displayedPasswordMinimumIsTheEnforcedOne(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig(passwordMinLength: 12)]);

        $html = $page->render();

        self::assertStringContainsString('Minimum 12 characters', $html);
        self::assertStringContainsString('minlength="12"', $html);
        self::assertStringNotContainsString('novalidate', $html);

        $page->name = 'John Doe';
        $page->email = 'john@example.com';
        $page->password = 'elevenchars';
        $page->passwordConfirmation = 'elevenchars';

        $page->submit();

        self::assertFalse($page->registered);
        self::assertStringContainsString('at least 12 characters', $page->error);
    }

    #[Test]
    public function aConfiguredMinimumBelowTheFloorIsRaisedNotDisplayed(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig(passwordMinLength: 4)]);

        $html = $page->render();

        self::assertStringContainsString('Minimum ' . PasswordHasherInterface::MIN_LENGTH . ' characters', $html);

        $page->name = 'John Doe';
        $page->email = 'john@example.com';
        $page->password = str_repeat('a', PasswordHasherInterface::MIN_LENGTH - 1);
        $page->passwordConfirmation = $page->password;

        $page->submit();

        self::assertFalse($page->registered);
        self::assertStringContainsString(
            'at least ' . PasswordHasherInterface::MIN_LENGTH . ' characters',
            $page->error,
        );
    }

    #[Test]
    public function anUnmountedPageStillEnforcesTheFloor(): void
    {
        $page = new SignupPage();
        $page->name = 'John Doe';
        $page->email = 'john@example.com';
        $page->password = 'short';
        $page->passwordConfirmation = 'short';

        $page->submit();

        self::assertFalse($page->registered);
        self::assertStringContainsString(
            'at least ' . PasswordHasherInterface::MIN_LENGTH . ' characters',
            $page->error,
        );
    }

    #[Test]
    public function submitSetsErrorOnValidationFailure(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig()]);
        $page->name = '';
        $page->email = '';
        $page->password = '';

        $page->submit();

        self::assertNotSame('', $page->error);
    }

    #[Test]
    public function submitSetsErrorWhenNoAuthenticator(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig()]);
        $page->name = 'John Doe';
        $page->email = 'john@example.com';
        $page->password = 'password123';
        $page->passwordConfirmation = 'password123';

        $page->submit();

        self::assertSame('Registration service unavailable.', $page->error);
    }

    #[Test]
    public function submitSuccessShowsConfirmation(): void
    {
        $auth = $this->createStub(AuthenticatorInterface::class);
        $auth->method('register')->willReturn(AuthResult::ok());

        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig(), 'authenticator' => $auth]);
        $page->name = 'John Doe';
        $page->email = 'john@example.com';
        $page->password = 'password123';
        $page->passwordConfirmation = 'password123';

        $page->submit();

        self::assertTrue($page->registered);
        self::assertSame('', $page->error);
    }

    #[Test]
    public function submitFailedRegistration(): void
    {
        $auth = $this->createStub(AuthenticatorInterface::class);
        $auth->method('register')->willReturn(AuthResult::failed('Email taken'));

        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig(), 'authenticator' => $auth]);
        $page->name = 'John';
        $page->email = 'john@example.com';
        $page->password = 'password123';
        $page->passwordConfirmation = 'password123';

        $page->submit();

        self::assertFalse($page->registered);
        self::assertSame('Email taken', $page->error);
    }

    #[Test]
    public function renderRegisteredStateShowsMessage(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig()]);
        $page->registered = true;

        $html = $page->render();

        self::assertStringContainsString('Account created', $html);
        self::assertStringContainsString('Sign in', $html);
    }

    #[Test]
    public function renderRegisteredWithEmailVerification(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig(requireEmailVerification: true)]);
        $page->registered = true;

        $html = $page->render();

        self::assertStringContainsString('verify your account', $html);
    }

    #[Test]
    public function renderShowsSocialButtons(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig(socialProviders: ['github'])]);

        $html = $page->render();

        self::assertStringContainsString('Continue with Github', $html);
    }

    #[Test]
    public function renderContainsLoginLink(): void
    {
        $page = new SignupPage();
        $page->mount(['config' => new AuthUiConfig()]);

        $html = $page->render();

        self::assertStringContainsString('Sign in', $html);
        self::assertStringContainsString('/login', $html);
    }
}
