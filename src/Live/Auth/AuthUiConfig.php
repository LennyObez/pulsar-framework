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
        /** @var list<string> $socialProviders */
        $socialProviders = is_array($data['social_providers'] ?? null) ? $data['social_providers'] : [];

        return new self(
            brandName: is_string($data['brand_name'] ?? null) ? $data['brand_name'] : '',
            logoUrl: is_string($data['logo_url'] ?? null) ? $data['logo_url'] : '',
            accentColor: is_string($data['accent_color'] ?? null) ? $data['accent_color'] : '#4f46e5',
            darkMode: is_bool($data['dark_mode'] ?? null) ? $data['dark_mode'] : false,
            socialProviders: $socialProviders,
            showRememberMe: is_bool($data['show_remember_me'] ?? null) ? $data['show_remember_me'] : true,
            showForgotPassword: is_bool($data['show_forgot_password'] ?? null) ? $data['show_forgot_password'] : true,
            requireEmailVerification: is_bool($data['require_email_verification'] ?? null) ? $data['require_email_verification'] : false,
            enableMfa: is_bool($data['enable_mfa'] ?? null) ? $data['enable_mfa'] : true,
            loginRedirect: is_string($data['login_redirect'] ?? null) ? $data['login_redirect'] : '/dashboard',
            logoutRedirect: is_string($data['logout_redirect'] ?? null) ? $data['logout_redirect'] : '/login',
            signupRedirect: is_string($data['signup_redirect'] ?? null) ? $data['signup_redirect'] : '/dashboard',
            passwordMinLength: isset($data['password_min_length']) && is_int($data['password_min_length']) ? $data['password_min_length'] : 8,
        );
    }
}
