<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Pulsar\Api\Api;
use Pulsar\Container\Compiler\PassRunner;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use Pulsar\Container\Scope\ScopeWideningException;

/**
 * Extended container interface for advanced DI features.
 *
 * Framework internals and opt-in extensions type-hint this interface when
 * they need tags, scopes, decoration, contextual bindings, or compilation.
 * Extension authors can continue depending on the stable minimal
 * {@see ContainerInterface} unless they opt in.
 * @api
 */
#[Api(since: '1.0.0')]
interface AdvancedContainerInterface extends ContainerInterface
{
    /**
     * Register a binding with an explicit lifetime.
     *
     * @param string $id Service identifier
     * @param callable|class-string $concrete Factory or class name
     * @param Lifetime $lifetime Service lifetime strategy
     */
    public function bindWithLifetime(string $id, callable|string $concrete, Lifetime $lifetime = Lifetime::Singleton): void;

    /**
     * Tag a registered service with metadata.
     *
     * @param string $id Service identifier to tag
     * @param string $tagName Tag name (e.g. 'event.listener')
     * @param int $priority Sorting priority (higher = earlier)
     * @param array<string, mixed> $attributes Arbitrary tag metadata
     */
    public function tag(string $id, string $tagName, int $priority = 0, array $attributes = []): void;

    /**
     * Get all service IDs tagged with the given tag name.
     *
     * Returns IDs sorted by (priority DESC, id ASC) for determinism.
     *
     * @return list<string>
     */
    public function getTaggedServiceIds(string $tag): array;

    /**
     * Register a decorator for a service.
     *
     * @param string $id Service identifier to decorate
     * @param class-string|callable $decorator Decorator class or factory
     * @param int $priority Application order (higher = outermost wrapper)
     */
    public function decorate(string $id, string|callable $decorator, int $priority = 0): void;

    /**
     * Begin a contextual binding definition.
     *
     * Usage: `$container->when(Consumer::class)->needs(Abstract::class)->give(Concrete::class)`
     *
     * @param string $consumer The consuming class FQCN
     */
    public function when(string $consumer): ContextualBindingBuilder;

    /**
     * Store a contextual binding (called by ContextualBindingBuilder).
     *
     * @param string $consumer Consumer class FQCN
     * @param string $abstract Abstract type being resolved
     * @param callable|class-string $concrete Concrete implementation
     */
    public function addContextualBinding(string $consumer, string $abstract, callable|string $concrete): void;

    /**
     * Begin request scope: enables RequestScope lifetime resolution.
     */
    public function beginRequestScope(): void;

    /**
     * End request scope: evicts all RequestScope instances.
     */
    public function endRequestScope(): void;

    /**
     * Begin tenant scope for the given tenant.
     *
     * @param string $tenantId Tenant identifier
     */
    public function beginTenantScope(string $tenantId): void;

    /**
     * End tenant scope: evicts all TenantScope instances.
     */
    public function endTenantScope(): void;

    /**
     * Run compiler passes against this container's definitions.
     */
    public function processCompilerPasses(PassRunner $runner): void;

    /**
     * Get all service definitions (for tooling and compilation).
     *
     * @return array<string, ServiceDefinition>
     */
    public function getDefinitions(): array;

    /**
     * Validate the scope graph for lifetime widening violations.
     *
     * Checks that longer-lived services do not depend on shorter-lived ones
     * (e.g., a Singleton depending on a RequestScope service).
     *
     * @throws ScopeWideningException If a scope widening violation is found
     */
    public function validateScopeGraph(): void;

    /**
     * Register a deferred service provider.
     *
     * Maps the provider's service IDs for lazy registration on first resolution.
     */
    public function registerDeferredProvider(DeferredServiceProviderInterface $provider): void;
}
