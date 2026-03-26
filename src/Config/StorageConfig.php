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
        );
    }
}
