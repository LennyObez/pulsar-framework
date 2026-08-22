<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Storage configuration for the admin panel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdminStorageConfig
{
    public function __construct(
        public string $driver,
        public ?string $sqlitePath,
    ) {}

    /**
     * @param array{
     *     driver?: string,
     *     sqlite_path?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            driver: $data['driver'] ?? 'sqlite',
            sqlitePath: $data['sqlite_path'] ?? null,
        );
    }
}
