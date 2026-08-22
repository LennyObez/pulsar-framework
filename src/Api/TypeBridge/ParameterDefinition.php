<?php

declare(strict_types=1);

namespace Pulsar\Api\TypeBridge;

use Pulsar\Api\Api;

/**
 * Represents a typed parameter in a route definition.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ParameterDefinition
{
    public function __construct(
        public string $name,
        public string $typeScriptType = 'string',
        public bool $optional = false,
    ) {}
}
