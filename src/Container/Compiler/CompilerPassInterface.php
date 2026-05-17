<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler;

use Pulsar\Api\Api;

/**
 * Contract for compiler passes that transform service definitions.
 *
 * Compiler passes run before the container is compiled (or used in dev mode)
 * and can modify, add, or remove service definitions.
 * @api
 */
#[Api(since: '1.0.0')]
interface CompilerPassInterface
{
    /**
     * Process the container builder's definitions.
     */
    public function process(ContainerBuilder $builder): void;
}
