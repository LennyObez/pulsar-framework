<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Metadata cache configuration DTO.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MetadataCacheConfig
{
    public function __construct(
        public string $driver,
        public string $path,
    ) {}

    /**
     * @param array{
     *     driver?: string,
     *     path?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            driver: $data['driver'] ?? 'array',
            path: $data['path'] ?? '',
        );
    }
}
