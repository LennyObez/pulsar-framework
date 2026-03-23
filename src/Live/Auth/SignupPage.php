<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

use function htmlspecialchars;
use function implode;

use const ENT_QUOTES;

/**
 * Drop-in signup page component with name, email, password, and social login.
 *
 * Handles form validation, password confirmation, and registration
 * through the AuthenticatorInterface. Supports social provider buttons
 * and email verification flow.
 * @api
 */
#[Api(since: '1.0.0')]
final class SignupPage extends LiveComponent
{
    #[LiveProp(writable: true)]
    public string $name = '';

    #[LiveProp(writable: true)]
    public string $email = '';

    #[LiveProp(writable: true)]
    public string $password = '';

    #[LiveProp(writable: true)]
    public string $passwordConfirmation = '';

    #[LiveProp]
    public string $error = '';

    #[LiveProp]
    public bool $registered = false;

    private ?AuthUiConfig $config = null;
    private ?AuthenticatorInterface $authenticator = null;

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
        $form = new SignupForm();
        $form->name = $this->name;
        $form->email = $this->email;
        $form->password = $this->password;
        $form->password_confirmation = $this->passwordConfirmation;

        if (!$form->validate()) {
            $messages = [];

            foreach ($form->errors() as $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $messages[] = $msg;
                }
            }

            $this->error = implode(' ', $messages);

            return;
        }

        if ($this->authenticator === null) {
            $this->error = 'Registration service unavailable.';

            return;
        }

        $result = $this->authenticator->register([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ]);

        if (!$result->success) {
            $this->error = $result->error ?? 'Registration failed.';

            return;
        }

        $this->registered = true;
        $this->error = '';
        $this->password = '';
        $this->passwordConfirmation = '';
    }

    public function render(): string
    {
        $config = $this->config ?? new AuthUiConfig();
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $darkClass = $config->darkMode ? ' pulsar-auth--dark' : '';
        $accentVar = $e($config->accentColor);

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

        if ($this->registered) {
            $message = $config->requireEmailVerification
                ? 'Check your email to verify your account.'
                : 'Your account has been created.';

            return <<<HTML
                <div class="pulsar-auth{$darkClass}" style="--pulsar-accent: {$accentVar}">
                    <div class="pulsar-auth__card">
                        {$logo}{$brandTitle}
                        <div class="pulsar-auth__success" role="status">
                            <h2 class="pulsar-auth__heading">Account created</h2>
                            <p class="pulsar-auth__text">{$e($message)}</p>
                            <a href="/login" class="pulsar-auth__submit" wire:navigate>Sign in</a>
                        </div>
                    </div>
                </div>
                HTML;
        }

        $errorHtml = '';
        if ($this->error !== '') {
            $errorHtml = '<div class="pulsar-auth__error" role="alert">' . $e($this->error) . '</div>';
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

        $nameVal = $e($this->name);
        $emailVal = $e($this->email);
        $minLen = $config->passwordMinLength;

        return <<<HTML
            <div class="pulsar-auth{$darkClass}" style="--pulsar-accent: {$accentVar}">
                <div class="pulsar-auth__card">
                    {$logo}{$brandTitle}
                    <h2 class="pulsar-auth__heading">Create your account</h2>
                    {$errorHtml}
                    <form wire:submit="submit" class="pulsar-auth__form" novalidate>
                        <div class="pulsar-auth__field">
                            <label for="signup-name" class="pulsar-auth__label">Full name</label>
                            <input id="signup-name" type="text" wire:model="name" value="{$nameVal}"
                                   class="pulsar-auth__input" autocomplete="name" required />
                        </div>
                        <div class="pulsar-auth__field">
                            <label for="signup-email" class="pulsar-auth__label">Email address</label>
                            <input id="signup-email" type="email" wire:model="email" value="{$emailVal}"
                                   class="pulsar-auth__input" autocomplete="email" required />
                        </div>
                        <div class="pulsar-auth__field">
                            <label for="signup-password" class="pulsar-auth__label">Password</label>
                            <input id="signup-password" type="password" wire:model="password"
                                   class="pulsar-auth__input" autocomplete="new-password"
                                   minlength="{$minLen}" required />
                            <span class="pulsar-auth__hint">Minimum {$minLen} characters</span>
                        </div>
                        <div class="pulsar-auth__field">
                            <label for="signup-password-confirm" class="pulsar-auth__label">Confirm password</label>
                            <input id="signup-password-confirm" type="password" wire:model="passwordConfirmation"
                                   class="pulsar-auth__input" autocomplete="new-password" required />
                        </div>
                        <button type="submit" class="pulsar-auth__submit">Create account</button>
                    </form>
                    {$socialHtml}
                    <p class="pulsar-auth__footer">
                        Already have an account? <a href="/login" class="pulsar-auth__link" wire:navigate>Sign in</a>
                    </p>
                </div>
            </div>
            HTML;
    }
}
