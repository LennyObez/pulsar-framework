<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler\Pass;

use Pulsar\Api\Internal;
use Pulsar\Container\Compiler\CompilerPassInterface;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\ServiceDefinition;
use Pulsar\Container\Tag\TaggedIterator;
use ReflectionClass;
use ReflectionNamedType;

use function array_map;
use function class_exists;
use function is_string;

/**
 * Resolves #[TaggedIterator] parameter attributes into concrete service lists.
 *
 * For each service whose concrete class has constructor parameters annotated
 * with #[TaggedIterator], this pass rewrites the binding to a factory that
 * collects and injects the tagged services at resolution time.
 */
#[Internal]
final class ResolveTaggedIteratorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $builder): void
    {
        foreach ($builder->allDefinitions() as $id => $definition) {
            $concrete = $definition->concrete;

            if (!is_string($concrete) || !class_exists($concrete)) {
                continue;
            }

            $reflector = new ReflectionClass($concrete);
            $constructor = $reflector->getConstructor();

            if ($constructor === null) {
                continue;
            }

            $hasTaggedIterator = false;

            foreach ($constructor->getParameters() as $parameter) {
                $attrs = $parameter->getAttributes(TaggedIterator::class);
                if ($attrs !== []) {
                    $hasTaggedIterator = true;
                    break;
                }
            }

            if (!$hasTaggedIterator) {
                continue;
            }

            // Rewrite the binding to a factory that resolves tagged iterators
            /** @var class-string $concreteClass */
            $concreteClass = $concrete;
            $factory = static function (ContainerInterface $container) use ($concreteClass, $builder): object {
                if (!class_exists($concreteClass)) {
                    throw new \RuntimeException('Class ' . $concreteClass . ' does not exist');
                }

                $reflector = new ReflectionClass($concreteClass);
                $constructor = $reflector->getConstructor();

                if ($constructor === null) {
                    return new $concreteClass();
                }

                $dependencies = array_map(
                    static function (\ReflectionParameter $parameter) use ($container, $builder): mixed {
                        $attrs = $parameter->getAttributes(TaggedIterator::class);

                        if ($attrs !== []) {
                            /** @var TaggedIterator $taggedIterator */
                            $taggedIterator = $attrs[0]->newInstance();
                            $taggedIds = $builder->findTaggedServiceIds($taggedIterator->tag);

                            return array_map(
                                static fn(string $taggedId): mixed => $container->get($taggedId),
                                $taggedIds,
                            );
                        }

                        $type = $parameter->getType();
                        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                            return $container->get($type->getName());
                        }

                        if ($parameter->isDefaultValueAvailable()) {
                            return $parameter->getDefaultValue();
                        }

                        return null;
                    },
                    $constructor->getParameters(),
                );

                return new $concreteClass(...$dependencies);
            };

            $builder->setDefinition($id, new ServiceDefinition(
                id: $id,
                concrete: $factory,
                lifetime: $definition->lifetime,
                tags: $definition->tags,
                lazy: $definition->lazy,
                decorators: $definition->decorators,
                contextFor: $definition->contextFor,
            ));
        }
    }
}
