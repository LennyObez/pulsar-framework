<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

use function array_map;

/**
 * Schema for a single configuration DTO class and its properties.
 */
#[Api(since: '1.0.0')]
final readonly class ConfigSchemaEntry
{
    /**
     * @param list<ConfigPropertySchema> $properties
     */
    public function __construct(
        public string $className,
        public array $properties = [],
    ) {}

    /**
     * @return array{class: string, properties: list<array{name: string, type: string, default: mixed}>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->className,
            'properties' => array_map(
                static fn(ConfigPropertySchema $p): array => $p->toArray(),
                $this->properties,
            ),
        ];
    }
}
