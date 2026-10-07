<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Schema;

use Pulsar\Api\Api;

/**
 * The full GraphQL schema: query root type and all named types.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Schema
{
    /**
     * @param ObjectType $queryType The root Query type
     * @param array<string, ObjectType> $types All named types (including Query), keyed by name
     */
    public function __construct(
        public ObjectType $queryType,
        public array $types,
    ) {}

    public function getType(string $name): ?ObjectType
    {
        return $this->types[$name] ?? null;
    }

    /**
     * Generate a simplified introspection result.
     *
     * @return array<string, mixed>
     */
    public function toIntrospection(): array
    {
        $types = [];

        foreach ($this->types as $type) {
            $types[] = $type->toIntrospection();
        }

        // Add scalar types
        foreach (['String', 'Int', 'Float', 'Boolean', 'ID'] as $scalar) {
            $types[] = ['kind' => 'SCALAR', 'name' => $scalar, 'fields' => null];
        }

        return [
            '__schema' => [
                'queryType' => ['name' => $this->queryType->name],
                'mutationType' => null,
                'subscriptionType' => null,
                'types' => $types,
            ],
        ];
    }
}
