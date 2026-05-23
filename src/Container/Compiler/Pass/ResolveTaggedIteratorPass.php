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
                $reflector = new ReflectionClass($concreteClass);
                $constructor = $reflector->getConstructor();

                if ($constructor === null) {
                    return new $concreteClass();
                }

                /** @var list<mixed> $dependencies */
                $dependencies = [];

                foreach ($constructor->getParameters() as $parameter) {
                    $attrs = $parameter->getAttributes(TaggedIterator::class);

                    if ($attrs !== []) {
                        /** @var TaggedIterator $taggedIterator */
                        $taggedIterator = $attrs[0]->newInstance();
                        $taggedIds = $builder->findTaggedServiceIds($taggedIterator->tag);
                        /** @var list<mixed> $services */
                        $services = [];
                        foreach ($taggedIds as $taggedId) {
                            /** @var mixed $service */
                            $service = $container->get($taggedId);
                            $services[] = $service;
                        }
                        $dependencies[] = $services;
                        continue;
                    }

                    $type = $parameter->getType();
                    if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                        $dependencies[] = $container->get($type->getName());
                    } elseif ($parameter->isDefaultValueAvailable()) {
                        /** @var mixed $paramDefault */
                        $paramDefault = $parameter->getDefaultValue();
                        $dependencies[] = $paramDefault;
                    }
                }

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
