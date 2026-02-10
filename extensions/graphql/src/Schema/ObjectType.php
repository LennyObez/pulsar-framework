<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Schema;

use Pulsar\Api\Api;

/**
 * A named GraphQL object type with a set of fields.
 */
#[Api(since: '1.0.0')]
final readonly class ObjectType
{
    /**
     * @param string $name Type name (PascalCase)
     * @param array<string, FieldDefinition> $fields Keyed by field name
     */
    public function __construct(
        public string $name,
        public array $fields,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toIntrospection(): array
    {
        $fields = [];

        foreach ($this->fields as $field) {
            $fields[] = $field->toIntrospection();
        }

        return [
            'kind' => 'OBJECT',
            'name' => $this->name,
            'fields' => $fields,
        ];
    }
}
