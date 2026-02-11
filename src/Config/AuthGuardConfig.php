<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Typed configuration DTO for a single authentication guard.
 */
#[Api(since: '1.0.0')]
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
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawName = $data['name'] ?? '';
        $name = is_string($rawName) ? $rawName : '';
        $rawDriver = $data['driver'] ?? 'session';
        $driver = is_string($rawDriver) ? $rawDriver : 'session';
        $enabled = (bool) ($data['enabled'] ?? true);

        return new self(
            name: $name,
            driver: $driver,
            enabled: $enabled,
        );
    }
}
