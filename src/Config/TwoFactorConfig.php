<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Typed configuration DTO for two-factor authentication settings.
 */
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
        $issuer = (string) ($data['issuer'] ?? 'Pulsar'); // @phpstan-ignore cast.string
        $codeDigits = (int) ($data['code_digits'] ?? 6); // @phpstan-ignore cast.int
        $codePeriod = (int) ($data['code_period'] ?? 30); // @phpstan-ignore cast.int
        $verificationWindow = (int) ($data['verification_window'] ?? 1); // @phpstan-ignore cast.int
        $recoveryCodeCount = (int) ($data['recovery_code_count'] ?? 8); // @phpstan-ignore cast.int

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
