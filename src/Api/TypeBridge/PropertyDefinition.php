<?php

declare(strict_types=1);

namespace Pulsar\Api\TypeBridge;

use Pulsar\Api\Api;

/**
 * Represents a property in a TypeScript interface definition.
 */
#[Api(since: '1.0.0')]
final readonly class PropertyDefinition
{
    public function __construct(
        public string $name,
        public string $typeScriptType = 'unknown',
        public bool $optional = false,
    ) {}
}
