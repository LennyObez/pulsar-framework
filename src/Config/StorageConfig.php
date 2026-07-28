<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/storage.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StorageConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/storage.php. */
    private const array KNOWN_KEYS = ['default', 'disks'];

    /**
     * @param array<string, DiskConfig> $disks Disk configurations keyed by name
     */
    public function __construct(
        public string $default = 'local',
        public array $disks = [],
        /** @var list<string> */
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
     * @param array{
     *     default?: string,
     *     disks?: array<string, array<string, mixed>>,
     * } $data Raw array from config/storage.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $disks = [];
        foreach ($data['disks'] ?? [] as $name => $diskData) {
            $disks[$name] = DiskConfig::fromArray($name, $diskData);
        }

        return new self(
            default: $environment->get('STORAGE_DISK') ?? $data['default'] ?? 'local',
            disks: $disks,
            unknownKeys: [
                ...UnknownKeys::collect($data, self::KNOWN_KEYS),
                // Disks are keyed by an operator-chosen name, so the report names the
                // disk: disks.s3.buckett rather than a bare "buckett".
                ...UnknownKeys::nestedEach('disks', $disks),
            ],
        );
    }
}
