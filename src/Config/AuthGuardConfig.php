<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Typed configuration DTO for a single authentication guard.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuthGuardConfig
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
        return new self(
            name: Coerce::string($data['name'] ?? null),
            driver: Coerce::string($data['driver'] ?? null, 'session'),
            enabled: (bool) ($data['enabled'] ?? true),
        );
    }
}
