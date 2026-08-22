<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Schema;

use Pulsar\Api\Api;

/**
 * A single argument on a GraphQL field.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ArgumentDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nonNull = false,
        public string|int|float|bool|null $defaultValue = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toIntrospection(): array
    {
        $typeRef = ['kind' => $this->typeKind(), 'name' => $this->type, 'ofType' => null];

        if ($this->nonNull) {
            $typeRef = ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $typeRef];
        }

        return [
            'name' => $this->name,
            'type' => $typeRef,
            'defaultValue' => $this->defaultValue,
        ];
    }

    private function typeKind(): string
    {
        return match ($this->type) {
            'String', 'Int', 'Float', 'Boolean', 'ID' => 'SCALAR',
            default => 'OBJECT',
        };
    }
}
