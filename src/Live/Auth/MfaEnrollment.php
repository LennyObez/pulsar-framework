<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\TwoFactorManagerInterface;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

use function htmlspecialchars;

use const ENT_QUOTES;

/**
 * MFA enrollment component with QR code display and code verification.
 *
 * Guides the user through 2FA setup:
 * 1. Displays the QR code (provisioning URI) for scanning with an authenticator app
 * 2. Accepts a verification code to confirm the setup
 * 3. Shows recovery codes for backup
 * @api
 */
#[Api(since: '1.0.0')]
final class MfaEnrollment extends LiveComponent
{
    #[LiveProp(writable: true)]
    public string $verificationCode = '';

    #[LiveProp]
    public string $error = '';

    #[LiveProp]
    public string $provisioningUri = '';

    #[LiveProp]
    public string $secretBase32 = '';

    /** @var list<string> */
    #[LiveProp]
    public array $recoveryCodes = [];

    #[LiveProp]
    public string $step = 'setup';

    #[LiveProp]
    public string $identityId = '';

    #[LiveProp]
    public string $secret = '';

    private ?TwoFactorManagerInterface $twoFactorManager = null;
    private ?AuthUiConfig $config = null;

    /**
     * @param array{
     *     twoFactorManager?: TwoFactorManagerInterface,
     *     identity?: IdentityInterface,
     *     config?: AuthUiConfig,
     * } $params
     */
    public function mount(array $params = []): void
    {
        $rawTwoFactor = $params['twoFactorManager'] ?? null;
        $twoFactorManager = $rawTwoFactor instanceof TwoFactorManagerInterface ? $rawTwoFactor : null;

        $rawIdentity = $params['identity'] ?? null;
        $identity = $rawIdentity instanceof IdentityInterface ? $rawIdentity : null;

        $this->twoFactorManager = $twoFactorManager;

        $rawConfig = $params['config'] ?? null;
        $this->config = $rawConfig instanceof AuthUiConfig ? $rawConfig : new AuthUiConfig();

        if ($twoFactorManager !== null && $identity !== null && $this->step === 'setup') {
            $setupData = $twoFactorManager->beginSetup($identity);
            $this->provisioningUri = $setupData['provisioning_uri'];
            $this->secretBase32 = $setupData['secret_base32'];
            $this->secret = $setupData['secret'];
            $this->recoveryCodes = $setupData['recovery_codes'];
            $this->identityId = $identity->id();
        }
    }

    #[LiveAction]
    public function confirmSetup(): void
    {
        if ($this->verificationCode === '') {
            $this->error = 'Please enter the 6-digit code from your authenticator app.';

            return;
        }

        if ($this->twoFactorManager === null) {
            $this->error = 'Two-factor service unavailable.';

            return;
        }

        if ($this->identityId === '' || $this->secret === '') {
            $this->error = 'Setup incomplete. Please start the enrollment process again.';

            return;
        }

        $result = $this->twoFactorManager->confirmSetup(
            $this->identityId,
            $this->secret,
            $this->verificationCode,
        );

        if (!$result->confirmed) {
            $this->error = 'Invalid code. Please try again with a new code from your app.';
            $this->verificationCode = '';

            return;
        }

        $this->step = 'recovery';
        $this->error = '';
    }

    #[LiveAction]
    public function complete(): void
    {
        $this->step = 'complete';
    }

    public function render(): string
    {
        $config = $this->config ?? new AuthUiConfig();
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $darkClass = $config->darkMode ? ' pulsar-auth--dark' : '';

        $errorHtml = '';
        if ($this->error !== '') {
            $errorHtml = '<div class="pulsar-auth__error" role="alert">' . $e($this->error) . '</div>';
        }

        if ($this->step === 'complete') {
            return <<<HTML
                <div class="pulsar-auth__mfa-enroll{$darkClass}">
                    <div class="pulsar-auth__success" role="status">
                        <h3 class="pulsar-auth__heading">Two-Factor Authentication Enabled</h3>
                        <p class="pulsar-auth__text">Your account is now protected with two-factor authentication.</p>
                    </div>
                </div>
                HTML;
        }

        if ($this->step === 'recovery') {
            $codeList = '';

            foreach ($this->recoveryCodes as $code) {
                $codeList .= '<li class="pulsar-auth__recovery-code">' . $e($code) . '</li>';
            }

            return <<<HTML
                <div class="pulsar-auth__mfa-enroll{$darkClass}">
                    <h3 class="pulsar-auth__heading">Save Your Recovery Codes</h3>
                    <p class="pulsar-auth__text">
                        Store these recovery codes in a safe place. Each code can only be used once
                        to sign in if you lose access to your authenticator app.
                    </p>
                    <ul class="pulsar-auth__recovery-list" role="list">{$codeList}</ul>
                    <button wire:click="complete" class="pulsar-auth__submit" type="button">
                        I've saved my recovery codes
                    </button>
                </div>
                HTML;
        }

        $qrUri = $e($this->provisioningUri);
        $secretDisplay = $e($this->secretBase32);

        return <<<HTML
            <div class="pulsar-auth__mfa-enroll{$darkClass}">
                <h3 class="pulsar-auth__heading">Set Up Two-Factor Authentication</h3>
                <p class="pulsar-auth__text">
                    Scan the QR code with your authenticator app (Google Authenticator, Authy, etc.)
                </p>
                <div class="pulsar-auth__qr" data-provisioning-uri="{$qrUri}">
                    <noscript>
                        <p>Manual entry key: <code>{$secretDisplay}</code></p>
                    </noscript>
                </div>
                <details class="pulsar-auth__details">
                    <summary>Can't scan the code?</summary>
                    <p>Enter this key manually: <code class="pulsar-auth__secret">{$secretDisplay}</code></p>
                </details>
                {$errorHtml}
                <form wire:submit="confirmSetup" class="pulsar-auth__form" novalidate>
                    <div class="pulsar-auth__field">
                        <label for="mfa-verify-code" class="pulsar-auth__label">Verification code</label>
                        <input id="mfa-verify-code" type="text" wire:model="verificationCode"
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               class="pulsar-auth__input pulsar-auth__input--code"
                               autocomplete="one-time-code" required />
                    </div>
                    <button type="submit" class="pulsar-auth__submit">Verify and activate</button>
                </form>
            </div>
            HTML;
    }
}
