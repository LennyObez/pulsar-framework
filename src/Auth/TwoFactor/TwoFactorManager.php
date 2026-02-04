<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Auth\Identity\IdentityInterface;
use Random\RandomException;

/**
 * Orchestrates TOTP and recovery code operations for two-factor authentication.
 */
final readonly class TwoFactorManager implements TwoFactorManagerInterface
{
    public function __construct(
        private TotpGenerator $generator,
        private TotpVerifier $verifier,
        private RecoveryCodeGenerator $recoveryCodeGenerator,
        private RecoveryCodeVerifier $recoveryCodeVerifier,
        private string $issuer = 'Pulsar',
        private int $recoveryCodeCount = 8,
    ) {}

    /**
     * @throws RandomException
     */
    public function beginSetup(IdentityInterface $identity): array
    {
        $secret = $this->generator->generateSecret();
        $base32 = $this->generator->encodeSecretBase32($secret);
        $uri = $this->generator->provisioningUri($secret, $identity->displayName(), $this->issuer);
        $recoveryCodes = $this->recoveryCodeGenerator->generate($this->recoveryCodeCount);

        return [
            'secret' => $secret,
            'secret_base32' => $base32,
            'provisioning_uri' => $uri,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    public function confirmSetup(string $secret, string $code): bool
    {
        return $this->verifier->verify($secret, $code);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return $this->verifier->verify($secret, $code);
    }

    public function verifyRecoveryCode(string $code, array $validCodes): int
    {
        return $this->recoveryCodeVerifier->verify($code, $validCodes);
    }
}
