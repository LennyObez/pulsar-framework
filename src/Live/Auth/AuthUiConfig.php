<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for pre-built auth UI components.
 *
 * Controls branding, social providers, field visibility, and routing
 * for the drop-in authentication components.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuthUiConfig
{
    /**
     * @param string $brandName Application name shown on auth pages
     * @param string $logoUrl URL to the logo image (empty = no logo)
     * @param string $accentColor Primary accent color (CSS value)
     * @param bool $darkMode Whether to use dark mode styles
     * @param list<string> $socialProviders Enabled social login providers (e.g., ['github', 'google'])
     * @param bool $showRememberMe Show "Remember me" checkbox on login
     * @param bool $showForgotPassword Show "Forgot password?" link on login
     * @param bool $requireEmailVerification Require email verification on signup
     * @param bool $enableMfa Allow MFA enrollment from the profile
     * @param string $loginRedirect URL to redirect after login
     * @param string $logoutRedirect URL to redirect after logout
     * @param string $signupRedirect URL to redirect after signup
     * @param int $passwordMinLength Minimum password length for signup/change
     */
    public function __construct(
        public string $brandName = '',
        public string $logoUrl = '',
        public string $accentColor = '#4f46e5',
        public bool $darkMode = false,
        public array $socialProviders = [],
        public bool $showRememberMe = true,
        public bool $showForgotPassword = true,
        public bool $requireEmailVerification = false,
        public bool $enableMfa = true,
        public string $loginRedirect = '/dashboard',
        public string $logoutRedirect = '/login',
        public string $signupRedirect = '/dashboard',
        public int $passwordMinLength = 8,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawSocialProviders = $data['social_providers'] ?? null;
        /** @var list<string> $socialProviders */
        $socialProviders = is_array($rawSocialProviders) ? $rawSocialProviders : [];

        $rawBrandName = $data['brand_name'] ?? null;
        $rawLogoUrl = $data['logo_url'] ?? null;
        $rawAccentColor = $data['accent_color'] ?? null;
        $rawDarkMode = $data['dark_mode'] ?? null;
        $rawShowRememberMe = $data['show_remember_me'] ?? null;
        $rawShowForgotPassword = $data['show_forgot_password'] ?? null;
        $rawRequireEmailVerification = $data['require_email_verification'] ?? null;
        $rawEnableMfa = $data['enable_mfa'] ?? null;
        $rawLoginRedirect = $data['login_redirect'] ?? null;
        $rawLogoutRedirect = $data['logout_redirect'] ?? null;
        $rawSignupRedirect = $data['signup_redirect'] ?? null;
        $rawPasswordMinLength = $data['password_min_length'] ?? null;

        return new self(
            brandName: is_string($rawBrandName) ? $rawBrandName : '',
            logoUrl: is_string($rawLogoUrl) ? $rawLogoUrl : '',
            accentColor: is_string($rawAccentColor) ? $rawAccentColor : '#4f46e5',
            darkMode: is_bool($rawDarkMode) ? $rawDarkMode : false,
            socialProviders: $socialProviders,
            showRememberMe: is_bool($rawShowRememberMe) ? $rawShowRememberMe : true,
            showForgotPassword: is_bool($rawShowForgotPassword) ? $rawShowForgotPassword : true,
            requireEmailVerification: is_bool($rawRequireEmailVerification) ? $rawRequireEmailVerification : false,
            enableMfa: is_bool($rawEnableMfa) ? $rawEnableMfa : true,
            loginRedirect: is_string($rawLoginRedirect) ? $rawLoginRedirect : '/dashboard',
            logoutRedirect: is_string($rawLogoutRedirect) ? $rawLogoutRedirect : '/login',
            signupRedirect: is_string($rawSignupRedirect) ? $rawSignupRedirect : '/dashboard',
            passwordMinLength: is_int($rawPasswordMinLength) ? $rawPasswordMinLength : 8,
        );
    }
}
