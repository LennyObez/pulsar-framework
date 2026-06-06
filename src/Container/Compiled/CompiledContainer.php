<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiled;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\AdvancedContainerInterface;
use Pulsar\Container\BindingType;
use Pulsar\Container\Compiler\PassRunner;
use Pulsar\Container\ContextualBindingBuilder;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use Pulsar\Container\Scope\ScopeManager;

use function array_keys;
use function array_pop;
use function in_array;

/**
 * Base class for compiled containers.
 *
 * Generated compiled containers extend this class and provide a
 * $methodMap of service IDs to factory method names. All mutation
 * methods throw ContainerException: compiled containers are read-only.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
abstract class CompiledContainer implements AdvancedContainerInterface
{
    /**
     * Map of service ID → factory method name.
     *
     * @var array<string, string>
     */
    protected array $methodMap = [];

    /**
     * Map of service ID → Lifetime enum value.
     *
     * @var array<string, Lifetime>
     */
    protected array $lifetimeMap = [];

    /**
     * Resolved singleton instances.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * IDs currently being resolved (for circular dependency detection).
     *
     * @var list<string>
     */
    private array $resolving = [];

    private ?ScopeManager $scopeManager = null;

    private bool $frozen = false;

    /**
     * Set the scope manager for scoped service resolution.
     */
    public function setScopeManager(ScopeManager $scopeManager): void
    {
        $this->scopeManager = $scopeManager;
    }

    /**
     * Freeze the container after bootstrap, blocking further instance() and forgetInstance() calls.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    #[NoDiscard]
    #[Override]
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->methodMap[$id])) {
            throw NotFoundException::forId($id);
        }

        $lifetime = $this->lifetimeMap[$id] ?? Lifetime::Singleton;

        // Check scoped instance cache
        if (($lifetime === Lifetime::RequestScope || $lifetime === Lifetime::TenantScope) && $this->scopeManager !== null) {
            $scopedInstance = $this->scopeManager->getScopedInstance($id, $lifetime);
            if ($scopedInstance !== null) {
                return $scopedInstance;
            }
        }

        // Circular dependency detection
        if (in_array($id, $this->resolving, true)) {
            throw ContainerException::circularDependency($id, $this->resolving);
        }

        $this->resolving[] = $id;

        try {
            $method = $this->methodMap[$id];
            /** @var object $instance */
            $instance = $this->$method();

            // Cache based on lifetime
            match ($lifetime) {
                Lifetime::Singleton => $this->instances[$id] = $instance,
                Lifetime::RequestScope, Lifetime::TenantScope => $this->scopeManager?->setScopedInstance($id, $lifetime, $instance),
                Lifetime::Transient => null,
            };

            return $instance;
        } finally {
            array_pop($this->resolving);
        }
    }

    #[Override]
    public function has(string $id): bool
    {
        return isset($this->methodMap[$id]) || isset($this->instances[$id]);
    }

    #[Override]
    public function instance(string $id, object $instance): void
    {
        if ($this->frozen) {
            throw new ContainerException(
                'Cannot modify a compiled container after freeze. Run `pulsar cache:clear` to use the dynamic container.',
            );
        }

        // Allow instance registration on compiled containers during Kernel bootstrap (before freeze)
        $this->instances[$id] = $instance;
    }

    #[Override]
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void
    {
        throw new ContainerException(
            'Cannot modify a compiled container. Run `pulsar cache:clear` to use the dynamic container.',
        );
    }

    #[Override]
    public function singleton(string $id, callable|string $concrete): void
    {
        $this->bind($id, $concrete, BindingType::Singleton);
    }

    #[Override]
    public function bindWithLifetime(string $id, callable|string $concrete, Lifetime $lifetime = Lifetime::Singleton): void
    {
        throw new ContainerException(
            'Cannot modify a compiled container. Run `pulsar cache:clear` to use the dynamic container.',
        );
    }

    #[Override]
    public function forgetInstance(string $id): void
    {
        if ($this->frozen) {
            throw new ContainerException(
                'Cannot modify a compiled container after freeze. Run `pulsar cache:clear` to use the dynamic container.',
            );
        }

        unset($this->instances[$id]);
    }

    #[Override]
    public function setResolutionHints(?array $hints): void
    {
        // No-op in compiled container: hints are baked in
    }

    #[NoDiscard]
    #[Override]
    public function getBindings(): array
    {
        /** @var list<string> */
        return array_keys($this->methodMap);
    }

    #[NoDiscard]
    #[Override]
    public function getInstances(): array
    {
        /** @var list<string> */
        return array_keys($this->instances);
    }

    #[Override]
    public function tag(string $id, string $tagName, int $priority = 0, array $attributes = []): void
    {
        throw new ContainerException(
            'Cannot modify a compiled container. Run `pulsar cache:clear` to use the dynamic container.',
        );
    }

    #[Override]
    public function getTaggedServiceIds(string $tag): array
    {
        // Subclasses may override with baked-in tag data
        return [];
    }

    #[Override]
    public function decorate(string $id, string|callable $decorator, int $priority = 0): void
    {
        throw new ContainerException(
            'Cannot modify a compiled container. Run `pulsar cache:clear` to use the dynamic container.',
        );
    }

    #[Override]
    public function when(string $consumer): ContextualBindingBuilder
    {
        throw new ContainerException(
            'Cannot modify a compiled container. Run `pulsar cache:clear` to use the dynamic container.',
        );
    }

    #[Override]
    public function beginRequestScope(): void
    {
        $this->scopeManager?->beginScope(Lifetime::RequestScope);
    }

    #[Override]
    public function endRequestScope(): void
    {
        $this->scopeManager?->endScope(Lifetime::RequestScope);
    }

    #[Override]
    public function beginTenantScope(string $tenantId): void
    {
        $this->scopeManager?->beginScope(Lifetime::TenantScope, $tenantId);
    }

    #[Override]
    public function endTenantScope(): void
    {
        $this->scopeManager?->endScope(Lifetime::TenantScope);
    }

    #[Override]
    public function processCompilerPasses(PassRunner $runner): void
    {
        throw new ContainerException(
            'Cannot modify a compiled container. Run `pulsar cache:clear` to use the dynamic container.',
        );
    }

    #[Override]
    public function getDefinitions(): array
    {
        return [];
    }

    /**
     * Store a contextual binding (not supported on compiled containers).
     */
    #[Override]
    public function addContextualBinding(string $consumer, string $abstract, callable|string $concrete): void
    {
        throw new ContainerException(
            'Cannot modify a compiled container. Run `pulsar cache:clear` to use the dynamic container.',
        );
    }

    #[Override]
    public function validateScopeGraph(): void
    {
        // No-op: scope validation happens at compile time
    }

    #[Override]
    public function registerDeferredProvider(DeferredServiceProviderInterface $provider): void
    {
        throw new ContainerException(
            'Cannot modify a compiled container. Run `pulsar cache:clear` to use the dynamic container.',
        );
    }

    #[Override]
    public function call(callable $callable, array $params = []): mixed
    {
        return $callable(...$params);
    }
}
