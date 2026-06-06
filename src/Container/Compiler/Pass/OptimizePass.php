<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler\Pass;

use Pulsar\Api\Internal;
use Pulsar\Container\Compiler\CompilerPassInterface;
use Pulsar\Container\Compiler\ContainerBuilder;
use ReflectionClass;
use ReflectionNamedType;

use function class_exists;
use function is_string;

/**
 * Pre-computes resolution hints from service definitions.
 *
 * Replaces the logic in OptimizeCommand::buildContainerHints() by scanning
 * all definitions' concrete classes for constructor parameters that can
 * be resolved via the container.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class OptimizePass implements CompilerPassInterface
{
    /**
     * Computed resolution hints after processing.
     *
     * @var array<class-string, list<array{name: string, type: class-string}>>
     */
    public array $hints = [];

    public function process(ContainerBuilder $builder): void
    {
        $this->hints = [];

        foreach ($builder->allDefinitions() as $id => $definition) {
            $concrete = $definition->concrete;

            if (!is_string($concrete) || !class_exists($concrete)) {
                continue;
            }

            $ref = new ReflectionClass($concrete);
            $constructor = $ref->getConstructor();

            if ($constructor === null) {
                continue;
            }

            $params = [];
            $skip = false;

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || $param->isVariadic()) {
                    $skip = true;
                    break;
                }

                $typeName = $type->getName();
                if ($typeName === 'self' || $typeName === 'static' || $typeName === 'parent') {
                    $skip = true;
                    break;
                }

                /** @var class-string $typeName */
                $params[] = ['name' => $param->getName(), 'type' => $typeName];
            }

            if (!$skip && $params !== []) {
                /** @var class-string $id */
                $this->hints[$id] = $params;
            }
        }
    }
}
