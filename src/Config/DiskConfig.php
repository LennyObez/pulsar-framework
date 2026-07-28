<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Per-disk storage configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DiskConfig implements ReportsUnknownKeys
{
    /** Keys read from a single entry of `disks` in config/storage.php. */
    private const array KNOWN_KEYS = [
        'driver', 'root', 'visibility', 'region', 'bucket', 'prefix', 'endpoint',
        'use_path_style',
    ];

    /**
     * @param list<string> $unknownKeys Keys present in this disk's raw array that the
     *     DTO does not read — a misspelled `visibility` silently leaves the disk
     *     private (or an S3 disk without its `bucket`), and the failure only shows up
     *     the first time something is written to it.
     */
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
     * @param array<string, mixed> $data Raw array for a single disk entry
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            driver: StorageDriver::from(Coerce::string($data['driver'] ?? null, 'local')),
            root: Coerce::string($data['root'] ?? null),
            visibility: Coerce::string($data['visibility'] ?? null, 'private'),
            region: Coerce::string($data['region'] ?? null),
            bucket: Coerce::string($data['bucket'] ?? null),
            prefix: Coerce::string($data['prefix'] ?? null),
            endpoint: Coerce::nullableString($data['endpoint'] ?? null),
            usePathStyle: (bool) ($data['use_path_style'] ?? false),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
