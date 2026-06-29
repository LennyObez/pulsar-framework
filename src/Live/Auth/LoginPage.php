<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;
use Pulsar\Live\CssColor;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

use function htmlspecialchars;
use function implode;

use const ENT_QUOTES;

/**
 * Drop-in login page component with email/password, social login, and MFA support.
 *
 * Usage:
 *   // In your route handler or template:
 *   $registry->register('login-page', LoginPage::class);
 *
 * The component handles:
 * - Email/password authentication
 * - "Remember me" checkbox
 * - "Forgot password" link
 * - Social login buttons (configurable providers)
 * - MFA challenge redirect (when identity has 2FA enabled)
 * - Error display with rate-limit awareness
 * - Dark mode and custom branding
 * @api
 */
#[Api(since: '1.0.0')]
final class LoginPage extends LiveComponent
{
    #[LiveProp(writable: true)]
    public string $email = '';

    #[LiveProp(writable: true)]
    public string $password = '';

    #[LiveProp(writable: true)]
    public bool $rememberMe = false;

    #[LiveProp]
    public string $error = '';

    #[LiveProp]
    public bool $requiresMfa = false;

    #[LiveProp]
    public string $mfaIdentityId = '';

    private ?AuthUiConfig $config = null;
    private ?AuthenticatorInterface $authenticator = null;

    /**
     * @param array{
     *     config?: AuthUiConfig,
     *     authenticator?: AuthenticatorInterface,
     * } $params
     */
    public function mount(array $params = []): void
    {
        $cfg = $params['config'] ?? null;
        $this->config = $cfg instanceof AuthUiConfig ? $cfg : new AuthUiConfig();
        $auth = $params['authenticator'] ?? null;
        $this->authenticator = $auth instanceof AuthenticatorInterface ? $auth : null;
    }

    #[LiveAction]
    public function submit(): void
    {
        $form = new LoginForm();
        $form->email = $this->email;
        $form->password = $this->password;
        $form->rememberMe = $this->rememberMe;

        if (!$form->validate()) {
            $errors = $form->errors();
            $messages = [];

            foreach ($errors as $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $messages[] = $msg;
                }
            }

            $this->error = implode(' ', $messages);

            return;
        }

        if ($this->authenticator === null) {
            $this->error = 'Authentication service unavailable.';

            return;
        }

        $result = $this->authenticator->attempt($this->email, $this->password, $this->rememberMe);

        if ($result->requiresMfa) {
            $this->requiresMfa = true;
            $this->mfaIdentityId = $result->identityId ?? '';
            $this->password = '';

            return;
        }

        if (!$result->success) {
            $this->error = $result->error ?? 'Invalid email or password.';
            $this->password = '';

            return;
        }

        // Authentication succeeded: redirect is handled by the frontend.
        // Clear the plaintext password so it is not dehydrated into the
        // encrypted state blob on subsequent round-trips.
        $this->error = '';
        $this->password = '';
    }

    public function render(): string
    {
        $config = $this->config ?? new AuthUiConfig();
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $darkClass = $config->darkMode ? ' pulsar-auth--dark' : '';
        $accentVar = $e(CssColor::sanitize($config->accentColor));

        $logo = '';
        if ($config->logoUrl !== '') {
            $logoUrl = $e($config->logoUrl);
            $brandAlt = $e($config->brandName ?: 'Logo');
            $logo = "<img src=\"{$logoUrl}\" alt=\"{$brandAlt}\" class=\"pulsar-auth__logo\" />";
        }

        $brandTitle = '';
        if ($config->brandName !== '') {
            $brandTitle = '<h1 class="pulsar-auth__brand">' . $e($config->brandName) . '</h1>';
        }

        $errorHtml = '';
        if ($this->error !== '') {
            $errorHtml = '<div class="pulsar-auth__error" role="alert">' . $e($this->error) . '</div>';
        }

        $rememberHtml = '';
        if ($config->showRememberMe) {
            $checked = $this->rememberMe ? ' checked' : '';
            $rememberHtml = <<<HTML
                <label class="pulsar-auth__remember">
                    <input type="checkbox" wire:model="rememberMe"{$checked} />
                    Remember me
                </label>
                HTML;
        }

        $forgotHtml = '';
        if ($config->showForgotPassword) {
            $forgotHtml = '<a href="/forgot-password" class="pulsar-auth__link" wire:navigate>Forgot password?</a>';
        }

        $socialHtml = '';
        if ($config->socialProviders !== []) {
            $buttons = '';

            foreach ($config->socialProviders as $provider) {
                $providerName = $e(ucfirst($provider));
                $providerSlug = $e($provider);
                $buttons .= <<<HTML
                    <a href="/auth/social/{$providerSlug}" class="pulsar-auth__social-btn pulsar-auth__social-btn--{$providerSlug}">
                        Continue with {$providerName}
                    </a>
                    HTML;
            }

            $socialHtml = <<<HTML
                <div class="pulsar-auth__divider"><span>or</span></div>
                <div class="pulsar-auth__social">{$buttons}</div>
                HTML;
        }

        if ($this->requiresMfa) {
            $mfaId = $e($this->mfaIdentityId);

            return <<<HTML
                <div class="pulsar-auth{$darkClass}" style="--pulsar-accent: {$accentVar}">
                    <div class="pulsar-auth__card">
                        {$logo}{$brandTitle}
                        <h2 class="pulsar-auth__heading">Two-Factor Authentication</h2>
                        <p class="pulsar-auth__text">Enter the code from your authenticator app.</p>
                        <pulsar-live name="mfa-challenge" identity-id="{$mfaId}"></pulsar-live>
                    </div>
                </div>
                HTML;
        }

        $emailVal = $e($this->email);

        return <<<HTML
            <div class="pulsar-auth{$darkClass}" style="--pulsar-accent: {$accentVar}">
                <div class="pulsar-auth__card">
                    {$logo}{$brandTitle}
                    <h2 class="pulsar-auth__heading">Sign in to your account</h2>
                    {$errorHtml}
                    <form wire:submit="submit" class="pulsar-auth__form" novalidate>
                        <div class="pulsar-auth__field">
                            <label for="login-email" class="pulsar-auth__label">Email address</label>
                            <input id="login-email" type="email" wire:model="email" value="{$emailVal}"
                                   class="pulsar-auth__input" autocomplete="email" required />
                        </div>
                        <div class="pulsar-auth__field">
                            <label for="login-password" class="pulsar-auth__label">Password</label>
                            <input id="login-password" type="password" wire:model="password"
                                   class="pulsar-auth__input" autocomplete="current-password" required />
                        </div>
                        <div class="pulsar-auth__options">
                            {$rememberHtml}{$forgotHtml}
                        </div>
                        <button type="submit" class="pulsar-auth__submit">Sign in</button>
                    </form>
                    {$socialHtml}
                    <p class="pulsar-auth__footer">
                        Don't have an account? <a href="/signup" class="pulsar-auth__link" wire:navigate>Sign up</a>
                    </p>
                </div>
            </div>
            HTML;
    }
}
