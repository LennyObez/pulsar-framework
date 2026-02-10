<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\BindingType;
use Pulsar\Container\ContainerInterface;

/**
 * Read-only container decorator for REPL safe mode.
 *
 * Delegates read operations to the inner container but blocks all mutations
 * (bind, instance, forgetInstance, setResolutionHints) to prevent users from
 * replacing safe-mode wrappers or evicting cached instances.
 */
#[Internal]
final readonly class ReadOnlyContainer implements ContainerInterface
{
    public function __construct(
        private ContainerInterface $inner,
    ) {}

    #[Override]
    #[NoDiscard]
    public function get(string $id): mixed
    {
        return $this->inner->get($id);
    }

    #[Override]
    public function has(string $id): bool
    {
        return $this->inner->has($id);
    }

    #[Override]
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void
    {
        throw ReplSafeModeException::operationBlocked('container mutation');
    }

    #[Override]
    public function instance(string $id, object $instance): void
    {
        throw ReplSafeModeException::operationBlocked('container mutation');
    }

    #[Override]
    public function forgetInstance(string $id): void
    {
        throw ReplSafeModeException::operationBlocked('container mutation');
    }

    #[Override]
    public function setResolutionHints(?array $hints): void
    {
        throw ReplSafeModeException::operationBlocked('container mutation');
    }

    #[Override]
    #[NoDiscard]
    public function getBindings(): array
    {
        return $this->inner->getBindings();
    }

    #[Override]
    #[NoDiscard]
    public function getInstances(): array
    {
        return $this->inner->getInstances();
    }
}
