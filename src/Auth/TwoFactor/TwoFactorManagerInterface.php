<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;
use SensitiveParameter;

/**
 * Contract for two-factor authentication management.
 *
 * BREAKING in 1.0.0-rc.8: verifyCode() and confirmSetup() now require
 * a string $identityId parameter for replay prevention scoping.
 * @api
 */
#[Api(since: '1.0.0')]
interface TwoFactorManagerInterface
{
    /**
     * Begin 2FA setup: generate a secret and recovery codes.
     *
     * @return array{secret: string, secret_base32: string, provisioning_uri: string, recovery_codes: list<string>}
     */
    public function beginSetup(IdentityInterface $identity): array;

    /**
     * Confirm 2FA setup by verifying the initial TOTP code.
     *
     * @param non-empty-string $identityId Identity of the user completing setup
     * @param string $secret Raw binary TOTP secret
     * @param string $code User-provided TOTP code
     */
    public function confirmSetup(
        string $identityId,
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $code,
    ): Confirm2faSetupResult;

    /**
     * Verify a TOTP code during login or step-up.
     *
     * Loads the secret from TotpSecretStoreInterface. If no store is configured
     * or no secret is found, returns Verify2faResult with VerifyReason::NotEnrolled.
     *
     * @param non-empty-string $identityId Identity of the user verifying
     * @param string $code User-provided TOTP code
     * @param TwoFactorPurpose $purpose The verification purpose
     */
    public function verifyCode(
        string $identityId,
        #[SensitiveParameter]
        string $code,
        TwoFactorPurpose $purpose = TwoFactorPurpose::Login,
    ): Verify2faResult;

    /**
     * Verify a TOTP code with an explicit secret.
     *
     * @deprecated Use verifyCode() with TotpSecretStoreInterface instead.
     *             This method will be removed in 2.0.
     *
     * @param non-empty-string $identityId Identity of the user verifying
     * @param string $secret Raw binary TOTP secret
     * @param string $code User-provided TOTP code
     * @param TwoFactorPurpose $purpose The verification purpose
     */
    public function verifyCodeWithSecret(
        string $identityId,
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $code,
        TwoFactorPurpose $purpose = TwoFactorPurpose::Login,
    ): Verify2faResult;

    /**
     * Verify a recovery code during login.
     *
     * @param non-empty-string $identityId Identity of the user
     * @param string $code User-provided recovery code
     * @param list<string> $validCodes Available recovery codes (for legacy non-store mode)
     * @return int Index of matched code, or -1 if no match
     */
    public function verifyRecoveryCode(
        string $identityId,
        #[SensitiveParameter]
        string $code,
        array $validCodes = [],
    ): int;

    /**
     * Rotate recovery codes: generate a new set, store it, invalidate the old set.
     *
     * @param non-empty-string $identityId Identity of the user
     */
    public function rotateRecoveryCodes(string $identityId): RecoveryCodeRotationResult;
}
