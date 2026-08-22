<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;

/**
 * Lightweight entity template for simple code generation: name, namespace, and field schema.
 *
 * For rich entity definitions built from database introspection or entity mappings,
 * use {@see \Pulsar\Codegen\Schema\EntityDefinition} instead.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EntityTemplate
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
