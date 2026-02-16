<?php

declare(strict_types=1);

namespace Pulsar\Container\Decorator;

use Closure;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\DecoratorDefinition;

use function array_reverse;
use function usort;

/**
 * Applies a chain of decorators to an inner service instance.
 *
 * Decorators are sorted by priority (highest first = outermost wrapper).
 * Each decorator receives the inner service as its first constructor parameter.
 */
#[Internal]
final class DecoratorChain
{
    /**
     * Apply decorators to the inner service.
     *
     * @param object $inner The original service instance
     * @param list<DecoratorDefinition> $decorators Decorator definitions to apply
     * @param ContainerInterface $container Container for resolving decorator dependencies
     * @return object The fully decorated service
     */
    #[NoDiscard]
    public static function resolve(object $inner, array $decorators, ContainerInterface $container): object
    {
        // Sort by priority DESC: highest priority wraps outermost
        $sorted = $decorators;
        usort($sorted, static fn(DecoratorDefinition $a, DecoratorDefinition $b): int => $b->priority <=> $a->priority);

        $current = $inner;

        // Apply in reverse order so highest priority ends up outermost
        $reversed = array_reverse($sorted);

        foreach ($reversed as $decorator) {
            if ($decorator->decorator instanceof Closure) {
                /** @var object $current */
                $current = ($decorator->decorator)($current, $container);
            } else {
                /** @var class-string $decoratorClass */
                $decoratorClass = $decorator->decorator;
                $current = new $decoratorClass($current);
            }
        }

        return $current;
    }
}
