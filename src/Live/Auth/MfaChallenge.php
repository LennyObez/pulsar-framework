<?php

declare(strict_types=1);

namespace Pulsar\Live\Auth;

use Pulsar\Api\Api;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

use function htmlspecialchars;
use function is_string;

use const ENT_QUOTES;

/**
 * MFA challenge component for TOTP code verification during login.
 *
 * Presented after successful email/password authentication when the
 * identity has 2FA enabled. Supports TOTP codes and recovery codes.
 * @api
 */
#[Api(since: '1.0.0')]
final class MfaChallenge extends LiveComponent
{
    #[LiveProp(writable: true)]
    public string $code = '';

    #[LiveProp]
    public string $identityId = '';

    #[LiveProp]
    public string $error = '';

    #[LiveProp]
    public bool $useRecoveryCode = false;

    private ?AuthenticatorInterface $authenticator = null;
    private ?AuthUiConfig $config = null;

    public function mount(array $params = []): void
    {
        $idRaw = $params['identity-id'] ?? $params['identityId'] ?? '';
        $this->identityId = is_string($idRaw) ? $idRaw : '';
        $auth = $params['authenticator'] ?? null;
        $this->authenticator = $auth instanceof AuthenticatorInterface ? $auth : null;
        $cfg = $params['config'] ?? null;
        $this->config = $cfg instanceof AuthUiConfig ? $cfg : new AuthUiConfig();
    }

    #[LiveAction]
    public function verify(): void
    {
        if ($this->code === '') {
            $this->error = $this->useRecoveryCode
                ? 'Please enter a recovery code.'
                : 'Please enter the 6-digit code.';

            return;
        }

        if ($this->authenticator === null) {
            $this->error = 'Authentication service unavailable.';

            return;
        }

        $result = $this->authenticator->verifyMfa($this->identityId, $this->code);

        if (!$result->success) {
            $this->error = $result->error ?? 'Invalid code. Please try again.';
            $this->code = '';

            return;
        }

        $this->error = '';
    }

    #[LiveAction]
    public function toggleRecoveryMode(): void
    {
        $this->useRecoveryCode = !$this->useRecoveryCode;
        $this->code = '';
        $this->error = '';
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

        if ($this->useRecoveryCode) {
            return <<<HTML
                <div class="pulsar-auth__mfa{$darkClass}">
                    <h3 class="pulsar-auth__heading">Recovery Code</h3>
                    <p class="pulsar-auth__text">Enter one of your recovery codes.</p>
                    {$errorHtml}
                    <form wire:submit="verify" class="pulsar-auth__form" novalidate>
                        <div class="pulsar-auth__field">
                            <label for="mfa-recovery" class="pulsar-auth__label">Recovery code</label>
                            <input id="mfa-recovery" type="text" wire:model="code"
                                   class="pulsar-auth__input pulsar-auth__input--mono"
                                   autocomplete="off" spellcheck="false" required />
                        </div>
                        <button type="submit" class="pulsar-auth__submit">Verify</button>
                    </form>
                    <button wire:click="toggleRecoveryMode" class="pulsar-auth__link" type="button">
                        Use authenticator app instead
                    </button>
                </div>
                HTML;
        }

        return <<<HTML
            <div class="pulsar-auth__mfa{$darkClass}">
                <h3 class="pulsar-auth__heading">Two-Factor Authentication</h3>
                <p class="pulsar-auth__text">Enter the 6-digit code from your authenticator app.</p>
                {$errorHtml}
                <form wire:submit="verify" class="pulsar-auth__form" novalidate>
                    <div class="pulsar-auth__field">
                        <label for="mfa-code" class="pulsar-auth__label">Authentication code</label>
                        <input id="mfa-code" type="text" wire:model="code" inputmode="numeric"
                               pattern="[0-9]{6}" maxlength="6"
                               class="pulsar-auth__input pulsar-auth__input--code"
                               autocomplete="one-time-code" required />
                    </div>
                    <button type="submit" class="pulsar-auth__submit">Verify</button>
                </form>
                <button wire:click="toggleRecoveryMode" class="pulsar-auth__link" type="button">
                    Use a recovery code instead
                </button>
            </div>
            HTML;
    }
}
