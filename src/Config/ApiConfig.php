<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Resource\ComplexityLimits;

use function is_array;
use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data Raw array from config/api.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawFormat = $data['default_format'] ?? 'json';
        $defaultFormat = is_string($rawFormat) ? $rawFormat : 'json';

        $rawPagination = $data['pagination'] ?? [];
        /** @var array<string, mixed> $pagination */
        $pagination = is_array($rawPagination) ? $rawPagination : [];

        $rawPaginationType = $pagination['type'] ?? 'offset';
        $paginationType = is_string($rawPaginationType) ? $rawPaginationType : 'offset';

        $rawPaginationDefault = $pagination['default_size'] ?? 25;
        $paginationDefaultSize = is_int($rawPaginationDefault) ? $rawPaginationDefault : 25;

        $rawPaginationMax = $pagination['max_size'] ?? 100;
        $paginationMaxSize = is_int($rawPaginationMax) ? $rawPaginationMax : 100;

        $rawVersioningStrategy = $data['versioning_strategy'] ?? 'url';
        $versioningStrategy = is_string($rawVersioningStrategy) ? $rawVersioningStrategy : 'url';

        $rawComplexity = $data['complexity_limits'] ?? [];
        /** @var array<string, mixed> $complexityArray */
        $complexityArray = is_array($rawComplexity) ? $rawComplexity : [];
        $complexityLimits = ComplexityLimits::fromArray($complexityArray);

        $entityBan = (bool) ($data['entity_serialization_ban'] ?? true);

        return new self(
            defaultFormat: $defaultFormat,
            paginationType: $paginationType,
            paginationDefaultSize: $paginationDefaultSize,
            paginationMaxSize: $paginationMaxSize,
            versioningStrategy: $versioningStrategy,
            complexityLimits: $complexityLimits,
            entitySerializationBanEnabled: $entityBan,
        );
    }
}
