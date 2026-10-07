<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Auth\AuthenticatorInterface;
use Pulsar\Live\Auth\AuthResult;
use Pulsar\Live\Auth\AuthUiConfig;
use Pulsar\Live\Auth\LoginPage;

#[CoversClass(LoginPage::class)]
final class LoginPageTest extends TestCase
{
    #[Test]
    public function renderContainsLoginForm(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig()]);

        $html = $page->render();

        self::assertStringContainsString('Sign in to your account', $html);
        self::assertStringContainsString('wire:submit="submit"', $html);
        self::assertStringContainsString('wire:model="email"', $html);
        self::assertStringContainsString('wire:model="password"', $html);
        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('type="password"', $html);
    }

    #[Test]
    public function renderShowsBrandingWhenConfigured(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(brandName: 'TestApp', logoUrl: '/logo.svg')]);

        $html = $page->render();

        self::assertStringContainsString('TestApp', $html);
        self::assertStringContainsString('/logo.svg', $html);
    }

    #[Test]
    public function renderShowsSocialButtons(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(socialProviders: ['github', 'google'])]);

        $html = $page->render();

        self::assertStringContainsString('Continue with Github', $html);
        self::assertStringContainsString('Continue with Google', $html);
        self::assertStringContainsString('/auth/social/github', $html);
    }

    #[Test]
    public function renderHidesRememberMeWhenDisabled(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(showRememberMe: false)]);

        $html = $page->render();

        self::assertStringNotContainsString('Remember me', $html);
    }

    #[Test]
    public function renderHidesForgotPasswordWhenDisabled(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(showForgotPassword: false)]);

        $html = $page->render();

        self::assertStringNotContainsString('Forgot password?', $html);
    }

    #[Test]
    public function renderAppliesDarkModeClass(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(darkMode: true)]);

        $html = $page->render();

        self::assertStringContainsString('pulsar-auth--dark', $html);
    }

    #[Test]
    public function renderEscapesUserInput(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig()]);
        $page->email = '<script>alert("xss")</script>';

        $html = $page->render();

        self::assertStringNotContainsString('<script>alert("xss")</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function submitSetsErrorWhenValidationFails(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig()]);
        $page->email = '';
        $page->password = '';

        $page->submit();

        self::assertNotSame('', $page->error);
    }

    #[Test]
    public function submitSetsErrorWhenNoAuthenticator(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig()]);
        $page->email = 'test@example.com';
        $page->password = 'password123';

        $page->submit();

        self::assertSame('Authentication service unavailable.', $page->error);
    }

    #[Test]
    public function submitWithFailedAuth(): void
    {
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $authenticator->method('attempt')->willReturn(AuthResult::failed('Bad credentials'));

        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(), 'authenticator' => $authenticator]);
        $page->email = 'test@example.com';
        $page->password = 'wrongpass';

        $page->submit();

        self::assertSame('Bad credentials', $page->error);
        self::assertSame('', $page->password);
    }

    #[Test]
    public function submitWithMfaRequired(): void
    {
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $authenticator->method('attempt')->willReturn(AuthResult::mfaRequired('user-42'));

        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(), 'authenticator' => $authenticator]);
        $page->email = 'test@example.com';
        $page->password = 'password123';

        $page->submit();

        self::assertTrue($page->requiresMfa);
        self::assertSame('user-42', $page->mfaIdentityId);
    }

    #[Test]
    public function submitSuccessful(): void
    {
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $authenticator->method('attempt')->willReturn(AuthResult::ok('/dashboard'));

        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(), 'authenticator' => $authenticator]);
        $page->email = 'test@example.com';
        $page->password = 'password123';

        $page->submit();

        self::assertSame('', $page->error);
        self::assertFalse($page->requiresMfa);
    }

    #[Test]
    public function submitSuccessfulClearsPlaintextPassword(): void
    {
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $authenticator->method('attempt')->willReturn(AuthResult::ok('/dashboard'));

        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(), 'authenticator' => $authenticator]);
        $page->email = 'test@example.com';
        $page->password = 'super-secret-password';

        $page->submit();

        // The plaintext password must not survive the successful-login path,
        // otherwise it is dehydrated into the encrypted state blob.
        self::assertSame('', $page->password);
    }

    #[Test]
    public function renderSanitizesMaliciousAccentColor(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(accentColor: '#000; background: url(//evil.com)')]);

        $html = $page->render();

        self::assertStringNotContainsString('url(//evil.com)', $html);
        self::assertStringContainsString('--pulsar-accent: #4f46e5', $html);
    }

    #[Test]
    public function renderKeepsValidAccentColor(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig(accentColor: '#abc123')]);

        $html = $page->render();

        self::assertStringContainsString('--pulsar-accent: #abc123', $html);
    }

    #[Test]
    public function renderMfaChallengeWhenRequired(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig()]);
        $page->requiresMfa = true;
        $page->mfaIdentityId = 'user-42';

        $html = $page->render();

        self::assertStringContainsString('Two-Factor Authentication', $html);
        self::assertStringContainsString('mfa-challenge', $html);
    }

    #[Test]
    public function renderContainsSignupLink(): void
    {
        $page = new LoginPage();
        $page->mount(['config' => new AuthUiConfig()]);

        $html = $page->render();

        self::assertStringContainsString('Sign up', $html);
        self::assertStringContainsString('/signup', $html);
    }
}
