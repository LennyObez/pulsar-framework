<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler\Pass;

use Pulsar\Api\Internal;
use Pulsar\Container\Compiler\CompilerPassInterface;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Exception\ContainerException;

use function class_exists;
use function is_string;
use function sprintf;

/**
 * Validates that decorator chains reference existing classes.
 */
#[Internal]
final class ValidateDecoratorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $builder): void
    {
        foreach ($builder->allDefinitions() as $id => $definition) {
            foreach ($definition->decorators as $decorator) {
                if (is_string($decorator->decorator) && !class_exists($decorator->decorator)) {
                    throw new ContainerException(sprintf(
                        'Decorator class "%s" for service "%s" does not exist.',
                        $decorator->decorator,
                        $id,
                    ));
                }
            }
        }
    }
}
