<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for two-factor authentication settings.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TwoFactorConfig
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
     * @param array{
     *     enabled?: bool|int|string,
     *     issuer?: string,
     *     code_digits?: int,
     *     code_period?: int,
     *     verification_window?: int,
     *     recovery_code_count?: int,
     *     recovery_code_bytes?: int,
     *     step_up_timeout_minutes?: int,
     *     recovery_code_algorithm_version?: int,
     *     allow_in_memory?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            issuer: $data['issuer'] ?? 'Pulsar',
            codeDigits: $data['code_digits'] ?? 6,
            codePeriod: $data['code_period'] ?? 30,
            verificationWindow: $data['verification_window'] ?? 1,
            recoveryCodeCount: $data['recovery_code_count'] ?? 8,
            recoveryCodeBytes: $data['recovery_code_bytes'] ?? 8,
            stepUpTimeoutMinutes: $data['step_up_timeout_minutes'] ?? 15,
            recoveryCodeAlgorithmVersion: $data['recovery_code_algorithm_version'] ?? 2,
            allowInMemory: (bool) ($data['allow_in_memory'] ?? false),
        );
    }
}
