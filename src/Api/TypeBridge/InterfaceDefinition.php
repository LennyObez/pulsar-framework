<?php

declare(strict_types=1);

namespace Pulsar\Api\TypeBridge;

use Pulsar\Api\Api;

/**
 * Represents a TypeScript interface for code generation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InterfaceDefinition
{
    /**
     * @param list<PropertyDefinition> $properties
     */
    public function __construct(
        public string $name,
        public array $properties,
    ) {}
}
