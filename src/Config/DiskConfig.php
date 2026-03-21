<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Per-disk storage configuration.
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
     * @param array<string, mixed> $data Raw array for a single disk entry
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data): self
    {
        $rawDriver = $data['driver'] ?? 'local';
        $driver = StorageDriver::from(is_string($rawDriver) ? $rawDriver : 'local');

        $rawRoot = $data['root'] ?? '';
        $rawVisibility = $data['visibility'] ?? 'private';
        $rawRegion = $data['region'] ?? '';
        $rawBucket = $data['bucket'] ?? '';
        $rawPrefix = $data['prefix'] ?? '';
        $rawEndpoint = $data['endpoint'] ?? null;

        return new self(
            name: $name,
            driver: $driver,
            root: is_string($rawRoot) ? $rawRoot : '',
            visibility: is_string($rawVisibility) ? $rawVisibility : 'private',
            region: is_string($rawRegion) ? $rawRegion : '',
            bucket: is_string($rawBucket) ? $rawBucket : '',
            prefix: is_string($rawPrefix) ? $rawPrefix : '',
            endpoint: is_string($rawEndpoint) ? $rawEndpoint : null,
            usePathStyle: (bool) ($data['use_path_style'] ?? false),
        );
    }
}
