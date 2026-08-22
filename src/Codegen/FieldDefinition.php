<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;

/**
 * Defines a single field within an entity for code generation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FieldDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        public bool $primary = false,
    ) {}
}
