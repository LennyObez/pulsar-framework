<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Contract for two-factor authentication management.
 */
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
     */
    public function confirmSetup(string $secret, string $code): bool;

    /**
     * Verify a TOTP code during login.
     */
    public function verifyCode(string $secret, string $code): bool;

    /**
     * Verify a recovery code during login.
     *
     * @param list<string> $validCodes
     * @return int Index of matched code, or -1 if no match
     */
    public function verifyRecoveryCode(string $code, array $validCodes): int;
}
