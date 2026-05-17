<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use Pulsar\Api\Api;
use Pulsar\Codegen\Schema\EntityDefinition;

/**
 * Contract for all code generators.
 *
 * Implementations produce a set of generated files from an entity definition
 * and generator configuration.
 * @api
 */
#[Api(since: '1.0.0')]
interface GeneratorInterface
{
    /**
     * Generate files for the given entity definition.
     */
    public function generate(EntityDefinition $entity, GeneratorConfig $config): GeneratedFileSet;
}
