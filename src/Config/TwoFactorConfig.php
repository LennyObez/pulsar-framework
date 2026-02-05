<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_int;
use function is_string;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for two-factor authentication settings.
 */
#[Api]
readonly class TwoFactorConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $issuer = 'Pulsar',
        public int $codeDigits = 6,
        public int $codePeriod = 30,
        public int $verificationWindow = 1,
        public int $recoveryCodeCount = 8,
    ) {}

    /**
     * Build from a raw two-factor config array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $enabled = (bool) ($data['enabled'] ?? false);
        $rawIssuer = $data['issuer'] ?? 'Pulsar';
        $issuer = is_string($rawIssuer) ? $rawIssuer : 'Pulsar';
        $rawCodeDigits = $data['code_digits'] ?? 6;
        $codeDigits = is_int($rawCodeDigits) ? $rawCodeDigits : (int) (is_numeric($rawCodeDigits) ? $rawCodeDigits : 6);
        $rawCodePeriod = $data['code_period'] ?? 30;
        $codePeriod = is_int($rawCodePeriod) ? $rawCodePeriod : (int) (is_numeric($rawCodePeriod) ? $rawCodePeriod : 30);
        $rawVerificationWindow = $data['verification_window'] ?? 1;
        $verificationWindow = is_int($rawVerificationWindow) ? $rawVerificationWindow : (int) (is_numeric($rawVerificationWindow) ? $rawVerificationWindow : 1);
        $rawRecoveryCodeCount = $data['recovery_code_count'] ?? 8;
        $recoveryCodeCount = is_int($rawRecoveryCodeCount) ? $rawRecoveryCodeCount : (int) (is_numeric($rawRecoveryCodeCount) ? $rawRecoveryCodeCount : 8);

        return new self(
            enabled: $enabled,
            issuer: $issuer,
            codeDigits: $codeDigits,
            codePeriod: $codePeriod,
            verificationWindow: $verificationWindow,
            recoveryCodeCount: $recoveryCodeCount,
        );
    }
}
