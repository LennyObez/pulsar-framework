<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Typed configuration DTO for `config/storage.php`.
 */
#[Api(since: '1.0.0')]
final readonly class StorageConfig
{
    /**
     * @param array<string, DiskConfig> $disks Disk configurations keyed by name
     */
    public function __construct(
        public string $default = 'local',
        public array $disks = [],
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/storage.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $default = $environment->get('STORAGE_DISK') ?? ($data['default'] ?? 'local');

        /** @var array<string, array<string, mixed>> $disksData */
        $disksData = $data['disks'] ?? [];

        $disks = [];
        foreach ($disksData as $name => $diskData) {
            /** @var array<string, mixed> $diskData */
            $disks[$name] = DiskConfig::fromArray($name, $diskData);
        }

        return new self(
            default: is_string($default) ? $default : 'local',
            disks: $disks,
        );
    }
}
