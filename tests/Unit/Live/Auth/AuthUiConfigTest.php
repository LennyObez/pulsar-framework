<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Auth\AuthUiConfig;

#[CoversClass(AuthUiConfig::class)]
final class AuthUiConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new AuthUiConfig();

        self::assertSame('', $config->brandName);
        self::assertSame('', $config->logoUrl);
        self::assertSame('#4f46e5', $config->accentColor);
        self::assertFalse($config->darkMode);
        self::assertSame([], $config->socialProviders);
        self::assertTrue($config->showRememberMe);
        self::assertTrue($config->showForgotPassword);
        self::assertFalse($config->requireEmailVerification);
        self::assertTrue($config->enableMfa);
        self::assertSame('/dashboard', $config->loginRedirect);
        self::assertSame('/login', $config->logoutRedirect);
        self::assertSame('/dashboard', $config->signupRedirect);
        self::assertSame(8, $config->passwordMinLength);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = AuthUiConfig::fromArray([
            'brand_name' => 'My App',
            'logo_url' => '/logo.png',
            'accent_color' => '#ff0000',
            'dark_mode' => true,
            'social_providers' => ['github', 'google'],
            'show_remember_me' => false,
            'show_forgot_password' => false,
            'require_email_verification' => true,
            'enable_mfa' => false,
            'login_redirect' => '/home',
            'logout_redirect' => '/bye',
            'signup_redirect' => '/welcome',
            'password_min_length' => 12,
        ]);

        self::assertSame('My App', $config->brandName);
        self::assertSame('/logo.png', $config->logoUrl);
        self::assertSame('#ff0000', $config->accentColor);
        self::assertTrue($config->darkMode);
        self::assertSame(['github', 'google'], $config->socialProviders);
        self::assertFalse($config->showRememberMe);
        self::assertFalse($config->showForgotPassword);
        self::assertTrue($config->requireEmailVerification);
        self::assertFalse($config->enableMfa);
        self::assertSame('/home', $config->loginRedirect);
        self::assertSame('/bye', $config->logoutRedirect);
        self::assertSame('/welcome', $config->signupRedirect);
        self::assertSame(12, $config->passwordMinLength);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = AuthUiConfig::fromArray([]);

        self::assertSame('', $config->brandName);
        self::assertSame('#4f46e5', $config->accentColor);
        self::assertTrue($config->showRememberMe);
        self::assertSame(8, $config->passwordMinLength);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = AuthUiConfig::fromArray([
            'brand_name' => 123,
            'dark_mode' => 'yes',
            'social_providers' => 'github',
            'password_min_length' => 'twelve',
        ]);

        self::assertSame('', $config->brandName);
        self::assertFalse($config->darkMode);
        self::assertSame([], $config->socialProviders);
        self::assertSame(8, $config->passwordMinLength);
    }
}
