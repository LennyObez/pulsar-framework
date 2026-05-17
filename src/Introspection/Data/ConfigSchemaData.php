<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

use function array_map;

/**
 * Aggregated configuration schema for all registered config DTOs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConfigSchemaData
{
    /**
     * @param list<ConfigSchemaEntry> $schemas
     */
    public function __construct(
        public array $schemas = [],
    ) {}

    /**
     * @return array{schemas: list<array{class: string, properties: list<array{name: string, type: string, default: mixed}>}>}
     */
    public function toArray(): array
    {
        return [
            'schemas' => array_map(
                static fn(ConfigSchemaEntry $e): array => $e->toArray(),
                $this->schemas,
            ),
        ];
    }
}
