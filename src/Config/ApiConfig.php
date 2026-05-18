<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Resource\ComplexityLimits;

/**
 * Typed configuration DTO for API tooling settings.
 *
 * Maps from the `api` key of `config/api.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ApiConfig
{
    public function __construct(
        public string $defaultFormat,
        public string $paginationType,
        public int $paginationDefaultSize,
        public int $paginationMaxSize,
        public string $versioningStrategy,
        public ComplexityLimits $complexityLimits,
        public bool $entitySerializationBanEnabled,
    ) {}

    /**
     * Build from the raw API config array.
     *
     * @param array{
     *     default_format?: string,
     *     pagination?: array{
     *         type?: string,
     *         default_size?: int,
     *         max_size?: int,
     *     },
     *     versioning_strategy?: string,
     *     complexity_limits?: array<string, mixed>,
     *     entity_serialization_ban?: bool|int|string,
     * } $data Raw array from config/api.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $pagination = $data['pagination'] ?? [];

        return new self(
            defaultFormat: $data['default_format'] ?? 'json',
            paginationType: $pagination['type'] ?? 'offset',
            paginationDefaultSize: $pagination['default_size'] ?? 25,
            paginationMaxSize: $pagination['max_size'] ?? 100,
            versioningStrategy: $data['versioning_strategy'] ?? 'url',
            complexityLimits: ComplexityLimits::fromArray($data['complexity_limits'] ?? []),
            entitySerializationBanEnabled: (bool) ($data['entity_serialization_ban'] ?? true),
        );
    }
}
