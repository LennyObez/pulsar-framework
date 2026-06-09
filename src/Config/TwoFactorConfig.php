<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            issuer: Coerce::string($data['issuer'] ?? null, 'Pulsar'),
            codeDigits: Coerce::int($data['code_digits'] ?? null, 6),
            codePeriod: Coerce::int($data['code_period'] ?? null, 30),
            verificationWindow: Coerce::int($data['verification_window'] ?? null, 1),
            recoveryCodeCount: Coerce::int($data['recovery_code_count'] ?? null, 8),
            recoveryCodeBytes: Coerce::int($data['recovery_code_bytes'] ?? null, 8),
            stepUpTimeoutMinutes: Coerce::int($data['step_up_timeout_minutes'] ?? null, 15),
            recoveryCodeAlgorithmVersion: Coerce::int($data['recovery_code_algorithm_version'] ?? null, 2),
            allowInMemory: (bool) ($data['allow_in_memory'] ?? false),
        );
    }
}
