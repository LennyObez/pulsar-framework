<?php

declare(strict_types=1);

namespace Pulsar\Container;

use LogicException;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Fluent builder for contextual bindings.
 *
 * Usage: `$container->when(Consumer::class)->needs(Abstract::class)->give(Concrete::class)`
 */
#[Api(since: '1.0.0')]
final class ContextualBindingBuilder
{
    private ?string $abstract = null;

    /**
     * @param string $consumer The consuming class FQCN
     * @param AdvancedContainerInterface $container The container to register the binding on
     */
    public function __construct(
        private readonly string $consumer,
        private readonly AdvancedContainerInterface $container,
    ) {}

    /**
     * Specify the abstract type the consumer needs.
     *
     * @param string $abstract The abstract type (interface or class FQCN)
     */
    #[NoDiscard]
    public function needs(string $abstract): self
    {
        $this->abstract = $abstract;

        return $this;
    }

    /**
     * Specify the concrete implementation to provide.
     *
     * @param callable|class-string $concrete The concrete type or factory
     */
    public function give(callable|string $concrete): void
    {
        if ($this->abstract === null) {
            throw new LogicException('Call needs() before give()');
        }

        $this->container->addContextualBinding($this->consumer, $this->abstract, $concrete);
    }
}
