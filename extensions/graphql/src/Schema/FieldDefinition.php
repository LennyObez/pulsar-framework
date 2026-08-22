<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Schema;

use Pulsar\Api\Api;

/**
 * A single field within a GraphQL object type.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FieldDefinition
{
    /**
     * @param string $name Field name
     * @param string $type GraphQL type (String, Int, Float, Boolean, ID, or custom type name)
     * @param bool $nonNull Whether the field is non-nullable
     * @param bool $isList Whether the field returns a list
     * @param bool $listItemNonNull Whether list items are non-nullable
     * @param array<string, ArgumentDefinition> $arguments Field arguments
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nonNull = false,
        public bool $isList = false,
        public bool $listItemNonNull = false,
        public array $arguments = [],
    ) {}

    /**
     * Serialize to introspection format.
     *
     * @return array<string, mixed>
     */
    public function toIntrospection(): array
    {
        $type = $this->buildTypeRef();

        $result = [
            'name' => $this->name,
            'type' => $type,
            'args' => [],
        ];

        foreach ($this->arguments as $arg) {
            $result['args'][] = $arg->toIntrospection();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTypeRef(): array
    {
        $inner = ['kind' => $this->typeKind(), 'name' => $this->type, 'ofType' => null];

        if ($this->isList) {
            $item = $this->listItemNonNull
                ? ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $inner]
                : $inner;
            $list = ['kind' => 'LIST', 'name' => null, 'ofType' => $item];

            return $this->nonNull
                ? ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $list]
                : $list;
        }

        return $this->nonNull
            ? ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $inner]
            : $inner;
    }

    private function typeKind(): string
    {
        return match ($this->type) {
            'String', 'Int', 'Float', 'Boolean', 'ID' => 'SCALAR',
            default => 'OBJECT',
        };
    }
}
