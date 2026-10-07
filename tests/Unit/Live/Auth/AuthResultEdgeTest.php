<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Auth\AuthResult;
use Pulsar\Live\Auth\AuthUiConfig;

/**
 * Edge case tests for AuthResult and AuthUiConfig.
 */
#[CoversClass(AuthResult::class)]
#[CoversClass(AuthUiConfig::class)]
final class AuthResultEdgeTest extends TestCase
{
    // --- AuthResult ---

    #[Test]
    public function okCreatesSuccessResult(): void
    {
        $result = AuthResult::ok('/dashboard');

        self::assertTrue($result->success);
        self::assertNull($result->error);
        self::assertFalse($result->requiresMfa);
        self::assertNull($result->identityId);
        self::assertSame('/dashboard', $result->redirectUrl);
    }

    #[Test]
    public function okWithoutRedirect(): void
    {
        $result = AuthResult::ok();

        self::assertTrue($result->success);
        self::assertNull($result->redirectUrl);
    }

    #[Test]
    public function failedCreatesErrorResult(): void
    {
        $result = AuthResult::failed('Invalid credentials');

        self::assertFalse($result->success);
        self::assertSame('Invalid credentials', $result->error);
        self::assertFalse($result->requiresMfa);
    }

    #[Test]
    public function mfaRequiredCreatesPartialResult(): void
    {
        $result = AuthResult::mfaRequired('user-123');

        self::assertFalse($result->success);
        self::assertTrue($result->requiresMfa);
        self::assertSame('user-123', $result->identityId);
        self::assertNull($result->error);
    }

    #[Test]
    public function constructorAllowsAllFields(): void
    {
        $result = new AuthResult(
            success: true,
            error: 'some error',
            requiresMfa: true,
            identityId: 'id-456',
            redirectUrl: '/home',
        );

        self::assertTrue($result->success);
        self::assertSame('some error', $result->error);
        self::assertTrue($result->requiresMfa);
        self::assertSame('id-456', $result->identityId);
        self::assertSame('/home', $result->redirectUrl);
    }

    // --- AuthUiConfig ---

    #[Test]
    public function authUiConfigDefaults(): void
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
    public function authUiConfigFromArrayWithFullData(): void
    {
        $config = AuthUiConfig::fromArray([
            'brand_name' => 'MyApp',
            'logo_url' => '/logo.png',
            'accent_color' => '#ff0000',
            'dark_mode' => true,
            'social_providers' => ['github', 'google'],
            'show_remember_me' => false,
            'show_forgot_password' => false,
            'require_email_verification' => true,
            'enable_mfa' => false,
            'login_redirect' => '/home',
            'logout_redirect' => '/',
            'signup_redirect' => '/welcome',
            'password_min_length' => 12,
        ]);

        self::assertSame('MyApp', $config->brandName);
        self::assertSame('/logo.png', $config->logoUrl);
        self::assertSame('#ff0000', $config->accentColor);
        self::assertTrue($config->darkMode);
        self::assertSame(['github', 'google'], $config->socialProviders);
        self::assertFalse($config->showRememberMe);
        self::assertFalse($config->showForgotPassword);
        self::assertTrue($config->requireEmailVerification);
        self::assertFalse($config->enableMfa);
        self::assertSame('/home', $config->loginRedirect);
        self::assertSame('/', $config->logoutRedirect);
        self::assertSame('/welcome', $config->signupRedirect);
        self::assertSame(12, $config->passwordMinLength);
    }

    #[Test]
    public function authUiConfigFromArrayUsesDefaults(): void
    {
        $config = AuthUiConfig::fromArray([]);

        self::assertSame('', $config->brandName);
        self::assertSame('#4f46e5', $config->accentColor);
        self::assertSame(8, $config->passwordMinLength);
    }

    #[Test]
    public function authUiConfigFromArrayHandlesInvalidTypes(): void
    {
        $config = AuthUiConfig::fromArray([
            'brand_name' => 42,
            'dark_mode' => 'yes',
            'social_providers' => 'not-array',
            'password_min_length' => 'eight',
        ]);

        self::assertSame('', $config->brandName);
        self::assertFalse($config->darkMode);
        self::assertSame([], $config->socialProviders);
        self::assertSame(8, $config->passwordMinLength);
    }
}
