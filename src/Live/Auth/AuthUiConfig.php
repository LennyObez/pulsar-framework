<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;

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
     * @param array{
     *     brand_name?: string,
     *     logo_url?: string,
     *     accent_color?: string,
     *     dark_mode?: bool,
     *     social_providers?: list<string>|array<array-key, mixed>,
     *     show_remember_me?: bool,
     *     show_forgot_password?: bool,
     *     require_email_verification?: bool,
     *     enable_mfa?: bool,
     *     login_redirect?: string,
     *     logout_redirect?: string,
     *     signup_redirect?: string,
     *     password_min_length?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawSocialProviders = $data['social_providers'] ?? null;
        /** @var list<string> $socialProviders */
        $socialProviders = is_array($rawSocialProviders) ? $rawSocialProviders : [];

        return new self(
            brandName: $data['brand_name'] ?? '',
            logoUrl: $data['logo_url'] ?? '',
            accentColor: $data['accent_color'] ?? '#4f46e5',
            darkMode: $data['dark_mode'] ?? false,
            socialProviders: $socialProviders,
            showRememberMe: $data['show_remember_me'] ?? true,
            showForgotPassword: $data['show_forgot_password'] ?? true,
            requireEmailVerification: $data['require_email_verification'] ?? false,
            enableMfa: $data['enable_mfa'] ?? true,
            loginRedirect: $data['login_redirect'] ?? '/dashboard',
            logoutRedirect: $data['logout_redirect'] ?? '/login',
            signupRedirect: $data['signup_redirect'] ?? '/dashboard',
            passwordMinLength: $data['password_min_length'] ?? 8,
        );
    }
}
