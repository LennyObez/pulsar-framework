<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Closure;
use Pulsar\Api\Internal;

/**
 * Immutable decorator metadata attached to a service definition.
 */
#[Internal]
final readonly class DecoratorDefinition
{
    /**
     * @param class-string|Closure $decorator Decorator class or factory
     * @param int $priority Application order (higher = applied first, wrapping outer)
     */
    public function __construct(
        public string|Closure $decorator,
        public int $priority = 0,
    ) {}
}
