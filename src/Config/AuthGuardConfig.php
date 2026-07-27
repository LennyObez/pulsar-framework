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
final readonly class AuthGuardConfig implements ReportsUnknownKeys
{
    /** Keys read from a single entry of `auth.guards` in config/security.php. */
    private const array KNOWN_KEYS = ['name', 'driver', 'enabled'];

    /**
     * @param list<string> $unknownKeys Keys present in this guard's raw array that the
     *     DTO does not read — a misspelled `driver` would otherwise define a guard
     *     backed by nothing.
     */
    public function __construct(
        public string $name,
        public string $driver,
        public bool $enabled = true,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
