<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Closure;
use Pulsar\Api\Api;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

use function htmlspecialchars;
use function is_string;

use const ENT_QUOTES;

/**
 * User profile management component.
 *
 * Provides sections for:
 * - Edit name and email
 * - Change password
 * - Manage MFA (enable/disable)
 * - View active sessions
 * @api
 */
#[Api(since: '1.0.0')]
final class UserProfile extends LiveComponent
{
    #[LiveProp(writable: true)]
    public string $name = '';

    #[LiveProp(writable: true)]
    public string $email = '';

    #[LiveProp(writable: true)]
    public string $currentPassword = '';

    #[LiveProp(writable: true)]
    public string $newPassword = '';

    #[LiveProp(writable: true)]
    public string $newPasswordConfirmation = '';

    #[LiveProp]
    public string $profileMessage = '';

    #[LiveProp]
    public string $profileError = '';

    #[LiveProp]
    public string $passwordMessage = '';

    #[LiveProp]
    public string $passwordError = '';

    #[LiveProp]
    public string $activeSection = 'profile';

    private ?AuthenticatorInterface $authenticator;
    private AuthUiConfig $config;

    public function mount(array $params = []): void
    {
        $auth = $params['authenticator'] ?? null;
        $this->authenticator = $auth instanceof AuthenticatorInterface ? $auth : null;
        $cfg = $params['config'] ?? null;
        $this->config = $cfg instanceof AuthUiConfig ? $cfg : new AuthUiConfig();

        if ($this->authenticator !== null) {
            $user = $this->authenticator->currentUser();

            if ($user !== null) {
                $this->name = (is_string($user['name'] ?? null) ? $user['name'] : '');
                $this->email = (is_string($user['email'] ?? null) ? $user['email'] : '');
            }
        }
    }

    #[LiveAction]
    public function updateProfile(): void
    {
        $this->profileMessage = '';
        $this->profileError = '';

        if ($this->name === '' || $this->email === '') {
            $this->profileError = 'Name and email are required.';

            return;
        }

        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $this->profileError = 'Please enter a valid email address.';

            return;
        }

        if ($this->authenticator === null) {
            $this->profileError = 'Profile service unavailable.';

            return;
        }

        $result = $this->authenticator->updateProfile([
            'name' => $this->name,
            'email' => $this->email,
        ]);

        if (!$result->success) {
            $this->profileError = $result->error ?? 'Failed to update profile.';

            return;
        }

        $this->profileMessage = 'Profile updated successfully.';
    }

    #[LiveAction]
    public function changePassword(): void
    {
        $this->passwordMessage = '';
        $this->passwordError = '';

        if ($this->currentPassword === '' || $this->newPassword === '') {
            $this->passwordError = 'Current and new passwords are required.';

            return;
        }

        $config = $this->config ?? new AuthUiConfig();

        if (mb_strlen($this->newPassword) < $config->passwordMinLength) {
            $this->passwordError = "New password must be at least {$config->passwordMinLength} characters.";

            return;
        }

        if ($this->newPassword !== $this->newPasswordConfirmation) {
            $this->passwordError = 'New password confirmation does not match.';

            return;
        }

        if ($this->authenticator === null) {
            $this->passwordError = 'Password service unavailable.';

            return;
        }

        $result = $this->authenticator->changePassword($this->currentPassword, $this->newPassword);

        if (!$result->success) {
            $this->passwordError = $result->error ?? 'Failed to change password.';

            return;
        }

        $this->passwordMessage = 'Password changed successfully.';
        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
    }

    #[LiveAction]
    public function switchSection(string $section): void
    {
        $this->activeSection = $section;
    }

    #[LiveAction]
    public function logout(): void
    {
        $this->authenticator?->logout();
    }

    public function render(): string
    {
        $config = $this->config ?? new AuthUiConfig();
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $darkClass = $config->darkMode ? ' pulsar-auth--dark' : '';
        $accentVar = $e($config->accentColor);

        $profileActive = $this->activeSection === 'profile' ? ' pulsar-auth__tab--active' : '';
        $passwordActive = $this->activeSection === 'password' ? ' pulsar-auth__tab--active' : '';
        $mfaActive = $this->activeSection === 'mfa' ? ' pulsar-auth__tab--active' : '';

        $mfaTab = '';
        if ($config->enableMfa) {
            $mfaTab = <<<HTML
                <button wire:click="switchSection('mfa')" class="pulsar-auth__tab{$mfaActive}" type="button">
                    Two-Factor Auth
                </button>
                HTML;
        }

        $sectionHtml = match ($this->activeSection) {
            'password' => $this->renderPasswordSection($e),
            'mfa' => $this->renderMfaSection($e),
            default => $this->renderProfileSection($e),
        };

        return <<<HTML
            <div class="pulsar-auth pulsar-auth--profile{$darkClass}" style="--pulsar-accent: {$accentVar}">
                <div class="pulsar-auth__card pulsar-auth__card--wide">
                    <h2 class="pulsar-auth__heading">Account Settings</h2>
                    <nav class="pulsar-auth__tabs" role="tablist">
                        <button wire:click="switchSection('profile')" class="pulsar-auth__tab{$profileActive}" type="button">
                            Profile
                        </button>
                        <button wire:click="switchSection('password')" class="pulsar-auth__tab{$passwordActive}" type="button">
                            Password
                        </button>
                        {$mfaTab}
                    </nav>
                    <div class="pulsar-auth__section">{$sectionHtml}</div>
                    <div class="pulsar-auth__footer-actions">
                        <button wire:click="logout" class="pulsar-auth__link pulsar-auth__link--danger" type="button">
                            Sign out
                        </button>
                    </div>
                </div>
            </div>
            HTML;
    }

    /** @param Closure(string): string $e */
    private function renderProfileSection(Closure $e): string
    {
        $nameVal = $e($this->name);
        $emailVal = $e($this->email);

        $msgHtml = '';
        if ($this->profileMessage !== '') {
            $msgHtml = '<div class="pulsar-auth__success" role="status">' . $e($this->profileMessage) . '</div>';
        }

        $errHtml = '';
        if ($this->profileError !== '') {
            $errHtml = '<div class="pulsar-auth__error" role="alert">' . $e($this->profileError) . '</div>';
        }

        return <<<HTML
            {$msgHtml}{$errHtml}
            <form wire:submit="updateProfile" class="pulsar-auth__form" novalidate>
                <div class="pulsar-auth__field">
                    <label for="profile-name" class="pulsar-auth__label">Full name</label>
                    <input id="profile-name" type="text" wire:model="name" value="{$nameVal}"
                           class="pulsar-auth__input" autocomplete="name" required />
                </div>
                <div class="pulsar-auth__field">
                    <label for="profile-email" class="pulsar-auth__label">Email address</label>
                    <input id="profile-email" type="email" wire:model="email" value="{$emailVal}"
                           class="pulsar-auth__input" autocomplete="email" required />
                </div>
                <button type="submit" class="pulsar-auth__submit">Save changes</button>
            </form>
            HTML;
    }

    /** @param Closure(string): string $e */
    private function renderPasswordSection(Closure $e): string
    {
        $config = $this->config ?? new AuthUiConfig();

        $msgHtml = '';
        if ($this->passwordMessage !== '') {
            $msgHtml = '<div class="pulsar-auth__success" role="status">' . $e($this->passwordMessage) . '</div>';
        }

        $errHtml = '';
        if ($this->passwordError !== '') {
            $errHtml = '<div class="pulsar-auth__error" role="alert">' . $e($this->passwordError) . '</div>';
        }

        $minLen = $config->passwordMinLength;

        return <<<HTML
            {$msgHtml}{$errHtml}
            <form wire:submit="changePassword" class="pulsar-auth__form" novalidate>
                <div class="pulsar-auth__field">
                    <label for="pw-current" class="pulsar-auth__label">Current password</label>
                    <input id="pw-current" type="password" wire:model="currentPassword"
                           class="pulsar-auth__input" autocomplete="current-password" required />
                </div>
                <div class="pulsar-auth__field">
                    <label for="pw-new" class="pulsar-auth__label">New password</label>
                    <input id="pw-new" type="password" wire:model="newPassword"
                           class="pulsar-auth__input" autocomplete="new-password"
                           minlength="{$minLen}" required />
                    <span class="pulsar-auth__hint">Minimum {$minLen} characters</span>
                </div>
                <div class="pulsar-auth__field">
                    <label for="pw-confirm" class="pulsar-auth__label">Confirm new password</label>
                    <input id="pw-confirm" type="password" wire:model="newPasswordConfirmation"
                           class="pulsar-auth__input" autocomplete="new-password" required />
                </div>
                <button type="submit" class="pulsar-auth__submit">Change password</button>
            </form>
            HTML;
    }

    /** @param Closure(string): string $e */
    private function renderMfaSection(Closure $e): string
    {
        return <<<HTML
            <div class="pulsar-auth__mfa-section">
                <p class="pulsar-auth__text">
                    Two-factor authentication adds an extra layer of security to your account.
                </p>
                <pulsar-live name="mfa-enrollment"></pulsar-live>
            </div>
            HTML;
    }
}
