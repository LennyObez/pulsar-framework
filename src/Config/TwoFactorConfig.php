<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_int;
use function is_string;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for two-factor authentication settings.
 */
#[Api(since: '1.0.0')]
readonly class TwoFactorConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $issuer = 'Pulsar',
        public int $codeDigits = 6,
        public int $codePeriod = 30,
        public int $verificationWindow = 1,
        public int $recoveryCodeCount = 8,
        public int $recoveryCodeBytes = 8,
        public int $stepUpTimeoutMinutes = 15,
        public int $recoveryCodeAlgorithmVersion = 2,
        public bool $allowInMemory = false,
    ) {}

    /**
     * Build from a raw two-factor config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
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
        $rawRecoveryCodeBytes = $data['recovery_code_bytes'] ?? 8;
        $recoveryCodeBytes = is_int($rawRecoveryCodeBytes) ? $rawRecoveryCodeBytes : (int) (is_numeric($rawRecoveryCodeBytes) ? $rawRecoveryCodeBytes : 8);
        $rawStepUpTimeoutMinutes = $data['step_up_timeout_minutes'] ?? 15;
        $stepUpTimeoutMinutes = is_int($rawStepUpTimeoutMinutes) ? $rawStepUpTimeoutMinutes : (int) (is_numeric($rawStepUpTimeoutMinutes) ? $rawStepUpTimeoutMinutes : 15);
        $rawRecoveryCodeAlgorithmVersion = $data['recovery_code_algorithm_version'] ?? 2;
        $recoveryCodeAlgorithmVersion = is_int($rawRecoveryCodeAlgorithmVersion) ? $rawRecoveryCodeAlgorithmVersion : (int) (is_numeric($rawRecoveryCodeAlgorithmVersion) ? $rawRecoveryCodeAlgorithmVersion : 2);
        $allowInMemory = (bool) ($data['allow_in_memory'] ?? false);

        return new self(
            enabled: $enabled,
            issuer: $issuer,
            codeDigits: $codeDigits,
            codePeriod: $codePeriod,
            verificationWindow: $verificationWindow,
            recoveryCodeCount: $recoveryCodeCount,
            recoveryCodeBytes: $recoveryCodeBytes,
            stepUpTimeoutMinutes: $stepUpTimeoutMinutes,
            recoveryCodeAlgorithmVersion: $recoveryCodeAlgorithmVersion,
            allowInMemory: $allowInMemory,
        );
    }
}
