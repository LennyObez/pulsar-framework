<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     name?: string,
     *     driver?: string,
     *     enabled?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            driver: $data['driver'] ?? 'session',
            enabled: (bool) ($data['enabled'] ?? true),
        );
    }
}
