<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Per-disk storage configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiskConfig
{
    public function __construct(
        public string $name,
        public StorageDriver $driver,
        public string $root = '',
        public string $visibility = 'private',
        public string $region = '',
        public string $bucket = '',
        public string $prefix = '',
        public ?string $endpoint = null,
        public bool $usePathStyle = false,
    ) {}

    /**
     * @param array{
     *     driver?: string,
     *     root?: string,
     *     visibility?: string,
     *     region?: string,
     *     bucket?: string,
     *     prefix?: string,
     *     endpoint?: string|null,
     *     use_path_style?: bool|int|string,
     * } $data Raw array for a single disk entry
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            driver: StorageDriver::from($data['driver'] ?? 'local'),
            root: $data['root'] ?? '',
            visibility: $data['visibility'] ?? 'private',
            region: $data['region'] ?? '',
            bucket: $data['bucket'] ?? '',
            prefix: $data['prefix'] ?? '',
            endpoint: $data['endpoint'] ?? null,
            usePathStyle: (bool) ($data['use_path_style'] ?? false),
        );
    }
}
