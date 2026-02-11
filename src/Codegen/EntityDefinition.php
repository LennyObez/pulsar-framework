<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;

/**
 * Defines an entity for code generation: its name, namespace, and field schema.
 */
#[Api(since: '1.0.0')]
final readonly class EntityDefinition
{
    /**
     * @param string $name Entity name (e.g., 'User', 'BlogPost')
     * @param string $namespace Target namespace (e.g., 'App\Models')
     * @param list<FieldDefinition> $fields Entity fields
     */
    public function __construct(
        public string $name,
        public string $namespace,
        public array $fields = [],
    ) {}
}
