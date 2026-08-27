<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Override;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;

/**
 * Keeps an extension's deferred service provider inside its own sandbox.
 *
 * A deferred provider is registered at boot but RUN much later — the first time
 * one of its `provides()` ids is resolved — and
 * {@see \Pulsar\Container\Provider\DeferredProviderRegistry::resolve()} calls
 * `register()` with the container doing the resolving. That container is the
 * real one, so deferring a provider was a way of turning an extension's scoped
 * registration into an unscoped one: the scope was established at boot and
 * simply not present at the moment it mattered.
 *
 * This wrapper carries the scope across that gap. It ignores the container it
 * is handed and calls the inner provider with the extension's scoped container,
 * so an extension's deferred `register()` sees exactly what its eager
 * `register()` would have seen.
 *
 * `provides()` and `isDeferred()` pass through untouched — the registry indexes
 * on them, and changing either would change which ids the provider claims.
 *
 * @internal Not part of the public API
 */
final readonly class ScopedDeferredProvider implements DeferredServiceProviderInterface
{
    private function __construct(
        private DeferredServiceProviderInterface $inner,
        private ContainerInterface $scoped,
    ) {}

    /**
     * Bind a provider to the scope it was registered in.
     *
     * Returns the provider unchanged when it is already wrapped, so a
     * re-registration cannot nest scopes.
     */
    #[NoDiscard]
    public static function wrap(
        DeferredServiceProviderInterface $provider,
        ContainerInterface $scoped,
    ): DeferredServiceProviderInterface {
        if ($provider instanceof self) {
            return $provider;
        }

        return new self($provider, $scoped);
    }

    /**
     * Register through the extension's scoped container, not the caller's.
     */
    #[Override]
    public function register(ContainerInterface $container): void
    {
        $this->inner->register($this->scoped);
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function provides(): array
    {
        return $this->inner->provides();
    }

    #[Override]
    public function isDeferred(): bool
    {
        return $this->inner->isDeferred();
    }
}
