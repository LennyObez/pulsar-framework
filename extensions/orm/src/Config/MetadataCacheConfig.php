<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Metadata cache configuration DTO.
 */
#[Api(since: '1.0.0')]
final readonly class MetadataCacheConfig
{
    public function __construct(
        public string $driver,
        public string $path,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var string $driver */
        $driver = $data['driver'] ?? 'array';

        /** @var string $path */
        $path = $data['path'] ?? '';

        return new self(
            driver: $driver,
            path: $path,
        );
    }
}
