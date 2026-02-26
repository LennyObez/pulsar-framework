<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler\Pass;

use Pulsar\Api\Internal;
use Pulsar\Container\Compiler\CompilerPassInterface;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Scope\ScopeWideningException;
use ReflectionClass;
use ReflectionNamedType;

use function class_exists;
use function is_string;

/**
 * Validates the scope graph: longer-lived services cannot depend on shorter-lived ones.
 *
 * For example, a Singleton cannot depend on a RequestScope service because
 * the singleton would hold a stale reference after the request scope ends.
 *
 * Scope hierarchy (longest to shortest): Singleton > TenantScope > RequestScope > Transient.
 */
#[Internal]
final class ValidateLifetimesPass implements CompilerPassInterface
{
    /** @var array<string, int> Lifetime scope rank (higher = longer-lived) */
    private const array SCOPE_RANK = [
        'singleton' => 4,
        'transient' => 1,
        'request' => 2,
        'tenant' => 3,
    ];

    public function process(ContainerBuilder $builder): void
    {
        foreach ($builder->allDefinitions() as $id => $definition) {
            $concrete = $definition->concrete;

            if (!is_string($concrete) || !class_exists($concrete)) {
                continue;
            }

            $parentRank = self::SCOPE_RANK[$definition->lifetime->value];

            $reflector = new ReflectionClass($concrete);
            $constructor = $reflector->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $depClass = $type->getName();
                $depDef = $builder->getDefinition($depClass);

                if ($depDef === null) {
                    continue;
                }

                $depRank = self::SCOPE_RANK[$depDef->lifetime->value];

                if ($parentRank > $depRank && $depDef->lifetime !== Lifetime::Transient) {
                    throw ScopeWideningException::detected(
                        $id,
                        $definition->lifetime,
                        $depClass,
                        $depDef->lifetime,
                    );
                }
            }
        }
    }
}
