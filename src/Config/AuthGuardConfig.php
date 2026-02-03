<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for a single authentication guard.
 */
#[Api]
readonly class AuthGuardConfig
{
    public function __construct(
        public string $name,
        public string $driver,
        public bool $enabled = true,
    ) {}

    /**
     * Build from a raw guard config array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = (string) ($data['name'] ?? ''); // @phpstan-ignore cast.string
        $driver = (string) ($data['driver'] ?? 'session'); // @phpstan-ignore cast.string
        $enabled = (bool) ($data['enabled'] ?? true);

        return new self(
            name: $name,
            driver: $driver,
            enabled: $enabled,
        );
    }
}
