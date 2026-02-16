<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Closure;
use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Compiler\Pass\ValidateLifetimesPass;
use Pulsar\Container\Compiler\PassRunner;
use Pulsar\Container\Decorator\DecoratorChain;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Container\Lazy\LazyServiceFactory;
use Pulsar\Container\Provider\DeferredProviderRegistry;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use Pulsar\Container\Scope\ScopeManager;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Throwable;

use function array_keys;
use function is_callable;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Array-based dependency injection container.
 *
 * Implements PSR-11 and provides singleton/factory binding support,
 * service tags, contextual bindings, scoped lifetimes, lazy proxies,
 * decorator chains, deferred providers, and compiler pass support.
 */
#[Api(since: '1.0.0')]
final class Container implements AdvancedContainerInterface
{
    /**
     * Service definitions indexed by ID.
     *
     * @var array<string, ServiceDefinition>
     */
    private array $definitions = [];

    /**
     * Resolved singleton instances.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * IDs currently being resolved (for circular dependency detection).
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Cached constructor parameter type maps (optimization hints).
     *
     * @var array<class-string, list<array{name: string, type: class-string}>>
     */
    public array $resolutionHints = [] {
        set(array $value) => $value;
    }

    /**
     * Contextual bindings: consumer → abstract → concrete.
     *
     * @var array<string, array<string, callable|class-string>>
     */
    private array $contextualBindings = [];

    private ?ScopeManager $scopeManager = null;
    private ?DeferredProviderRegistry $deferredProviders = null;

    /**
     * Set the scope manager (injected at Kernel boot, null in test/simple usage).
     */
    public function setScopeManager(ScopeManager $scopeManager): void
    {
        $this->scopeManager = $scopeManager;
    }

    /**
     * Set the deferred provider registry.
     */
    public function setDeferredProviderRegistry(DeferredProviderRegistry $registry): void
    {
        $this->deferredProviders = $registry;
    }

    /**
     * Load optimization hints from cache.
     *
     * Hints are fallible; if a hint fails at resolution time,
     * the container silently falls back to reflection. Passing null
     * clears all hints.
     *
     * @param array<class-string, list<array{name: string, type: class-string}>>|null $hints
     */
    #[Override]
    public function setResolutionHints(?array $hints): void
    {
        $this->resolutionHints = $hints ?? [];
    }

    #[Override]
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void
    {
        $this->bindWithLifetime($id, $concrete, $type->toLifetime());
    }

    #[Override]
    public function singleton(string $id, callable|string $concrete): void
    {
        $this->bind($id, $concrete, BindingType::Singleton);
    }

    #[Override]
    public function bindWithLifetime(string $id, callable|string $concrete, Lifetime $lifetime = Lifetime::Singleton): void
    {
        /** @var class-string|Closure $normalized */
        $normalized = is_string($concrete) ? $concrete : Closure::fromCallable($concrete);

        $this->definitions[$id] = new ServiceDefinition(
            id: $id,
            concrete: $normalized,
            lifetime: $lifetime,
        );

        // Clear any cached instance if rebinding
        unset($this->instances[$id]);
    }

    #[Override]
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    #[Override]
    public function has(string $id): bool
    {
        return isset($this->definitions[$id])
            || isset($this->instances[$id])
            || ($this->deferredProviders !== null && $this->deferredProviders->has($id));
    }

    /**
     * @throws NotFoundException
     * @throws ContainerException
     * @throws ReflectionException If class reflection fails during autowiring
     */
    #[NoDiscard]
    #[Override]
    public function get(string $id): mixed
    {
        // Return cached instance if available
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Check for binding
        if (isset($this->definitions[$id])) {
            return $this->resolve($id);
        }

        // Check deferred providers before giving up
        if ($this->deferredProviders !== null && $this->deferredProviders->has($id)) {
            $this->deferredProviders->resolve($id, $this);

            return $this->resolveAfterDeferredRegistration($id);
        }

        throw NotFoundException::forId($id);
    }

    #[Override]
    public function tag(string $id, string $tagName, int $priority = 0, array $attributes = []): void
    {
        if (!isset($this->definitions[$id])) {
            throw ContainerException::unresolvable($id, 'Cannot tag unregistered service');
        }

        $this->definitions[$id] = $this->definitions[$id]->withTags(
            new TagDefinition($tagName, $priority, $attributes),
        );
    }

    #[Override]
    public function getTaggedServiceIds(string $tag): array
    {
        return Tag\TagCollector::collectIds($tag, $this->definitions);
    }

    #[Override]
    public function decorate(string $id, string|callable $decorator, int $priority = 0): void
    {
        if (!isset($this->definitions[$id])) {
            throw ContainerException::unresolvable($id, 'Cannot decorate unregistered service');
        }

        /** @var class-string|Closure $normalizedDecorator */
        $normalizedDecorator = is_string($decorator) ? $decorator : Closure::fromCallable($decorator);

        $this->definitions[$id] = $this->definitions[$id]->withDecorators(
            new DecoratorDefinition($normalizedDecorator, $priority),
        );

        // Clear cached instance so decoration applies on next resolution
        unset($this->instances[$id]);
    }

    #[Override]
    public function when(string $consumer): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($consumer, $this);
    }

    /**
     * Store a contextual binding (called by ContextualBindingBuilder).
     *
     * @param string $consumer Consumer class FQCN
     * @param string $abstract Abstract type being resolved
     * @param callable|class-string $concrete Concrete implementation
     */
    #[Override]
    public function addContextualBinding(string $consumer, string $abstract, callable|string $concrete): void
    {
        $this->contextualBindings[$consumer][$abstract] = $concrete;
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
        $builder = new ContainerBuilder();

        foreach ($this->definitions as $id => $definition) {
            $builder->setDefinition($id, $definition);
        }

        $runner->run($builder);

        // Re-import processed definitions
        $this->definitions = [];
        foreach ($builder->allDefinitions() as $id => $definition) {
            $this->definitions[$id] = $definition;
        }
    }

    #[Override]
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Resolve a binding to its concrete implementation.
     *
     * @throws ContainerException
     * @throws ReflectionException If class reflection fails during autowiring
     */
    /**
     * Resolve after deferred provider registration.
     *
     * Extracted to its own method so Psalm flow analysis doesn't carry
     * the earlier isset() narrowing into this scope.
     *
     * @throws NotFoundException
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function resolveAfterDeferredRegistration(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->definitions[$id])) {
            return $this->resolve($id);
        }

        throw NotFoundException::forId($id);
    }

    private function resolve(string $id): object
    {
        // Circular dependency detection: O(1) via associative array
        if (isset($this->resolving[$id])) {
            throw ContainerException::circularDependency($id, array_keys($this->resolving));
        }

        $this->resolving[$id] = true;

        try {
            $definition = $this->definitions[$id];
            $concrete = $definition->concrete;
            $lifetime = $definition->lifetime;

            // Check scoped instance cache
            if (($lifetime === Lifetime::RequestScope || $lifetime === Lifetime::TenantScope) && $this->scopeManager !== null) {
                $scopedInstance = $this->scopeManager->getScopedInstance($id, $lifetime);
                if ($scopedInstance !== null) {
                    return $scopedInstance;
                }
            }

            // Build the instance: lazy proxy wrapping if flagged
            if ($definition->lazy) {
                $instance = LazyServiceFactory::create($id, $concrete, $this);
            } elseif ($concrete instanceof Closure) {
                $instance = $concrete($this);
            } else {
                $instance = $this->build($concrete);
            }

            if (!is_object($instance)) {
                throw ContainerException::unresolvable(
                    $id,
                    'Factory must return an object',
                );
            }

            // Apply decorator chain
            if ($definition->decorators !== []) {
                $instance = DecoratorChain::resolve($instance, $definition->decorators, $this);
            }

            // Cache based on lifetime
            match ($lifetime) {
                Lifetime::Singleton => $this->instances[$id] = $instance,
                Lifetime::RequestScope, Lifetime::TenantScope => $this->scopeManager?->setScopedInstance($id, $lifetime, $instance),
                Lifetime::Transient => null,
            };

            return $instance;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Build a class instance using autowiring.
     *
     * If resolution hints exist for this class, tries the cached parameter
     * map first. On any failure, falls back transparently to reflection.
     *
     * @param class-string $className
     *
     * @throws ContainerException
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function build(string $className): object
    {
        if (!class_exists($className)) {
            throw ContainerException::unresolvable(
                $className,
                sprintf('Class "%s" does not exist', $className),
            );
        }

        // Try cached resolution hints first
        if (isset($this->resolutionHints[$className])) {
            try {
                return $this->buildFromHints($className, $this->resolutionHints[$className]);
            } catch (Throwable) {
                // Fallback to reflection
            }
        }

        return $this->buildFromReflection($className);
    }

    /**
     * Build from cached resolution hints (optimization path).
     *
     * @param class-string $className
     * @param list<array{name: string, type: class-string}> $hints
     *
     * @throws NotFoundException If a dependency cannot be found in the container
     * @throws ContainerException If a container error occurs during resolution
     * @throws ReflectionException If class reflection fails during dependency autowiring
     */
    private function buildFromHints(string $className, array $hints): object
    {
        $dependencies = [];

        foreach ($hints as $hint) {
            $dependencies[] = $this->get($hint['type']);
        }

        return new $className(...$dependencies);
    }

    /**
     * Build from reflection (standard path).
     *
     * @param class-string $className
     *
     * @throws ContainerException
     * @throws ReflectionException If class reflection fails
     */
    private function buildFromReflection(string $className): object
    {
        $reflector = new ReflectionClass($className);

        if (!$reflector->isInstantiable()) {
            throw ContainerException::unresolvable(
                $className,
                sprintf('Class "%s" is not instantiable', $className),
            );
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw ContainerException::unresolvable(
                    $className,
                    sprintf(
                        'Parameter "%s" has no type hint and no default value',
                        $parameter->getName(),
                    ),
                );
            }

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw ContainerException::unresolvable(
                    $className,
                    sprintf(
                        'Parameter "%s" requires a non-class type "%s"',
                        $parameter->getName(),
                        $type instanceof ReflectionNamedType ? $type->getName() : 'unknown',
                    ),
                );
            }

            $dependencyClass = $type->getName();

            // Check contextual bindings first
            $resolvedContextual = $this->resolveContextual($className, $dependencyClass);
            if ($resolvedContextual !== null) {
                $dependencies[] = $resolvedContextual;
                continue;
            }

            try {
                $dependencies[] = $this->get($dependencyClass);
            } catch (NotFoundException) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } elseif ($type->allowsNull()) {
                    $dependencies[] = null;
                } else {
                    throw ContainerException::unresolvable(
                        $className,
                        sprintf(
                            'Unable to resolve dependency "%s" for parameter "%s"',
                            $dependencyClass,
                            $parameter->getName(),
                        ),
                    );
                }
            }
        }

        return new $className(...$dependencies);
    }

    /**
     * Resolve a contextual binding for a consumer + abstract pair.
     *
     * @return object|null The resolved instance, or null if no contextual binding exists
     *
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function resolveContextual(string $consumer, string $abstract): ?object
    {
        if (!isset($this->contextualBindings[$consumer][$abstract])) {
            return null;
        }

        $concrete = $this->contextualBindings[$consumer][$abstract];

        if (is_callable($concrete)) {
            /** @var object|null */
            return $concrete($this);
        }

        if (isset($this->definitions[$concrete]) || isset($this->instances[$concrete])) {
            /** @var object|null */
            return $this->get($concrete);
        }

        /** @var class-string $concrete */
        return $this->build($concrete);
    }

    #[Override]
    public function forgetInstance(string $id): void
    {
        unset($this->instances[$id]);
    }

    /**
     * Get all registered binding IDs.
     *
     * @return list<string>
     */
    #[NoDiscard]
    #[Override]
    public function getBindings(): array
    {
        /** @var list<string> */
        return array_keys($this->definitions);
    }

    /**
     * Get all cached instance IDs.
     *
     * @return list<string>
     */
    #[NoDiscard]
    #[Override]
    public function getInstances(): array
    {
        /** @var list<string> */
        return array_keys($this->instances);
    }

    #[Override]
    public function validateScopeGraph(): void
    {
        $builder = new ContainerBuilder();

        foreach ($this->definitions as $id => $definition) {
            $builder->setDefinition($id, $definition);
        }

        $pass = new ValidateLifetimesPass();
        $pass->process($builder);
    }

    #[Override]
    public function registerDeferredProvider(DeferredServiceProviderInterface $provider): void
    {
        if ($this->deferredProviders === null) {
            $this->deferredProviders = new DeferredProviderRegistry();
        }

        $this->deferredProviders->register($provider);
    }

    /**
     * Call a callable, resolving type-hinted parameters from the container.
     *
     * @param callable $callable The callable to invoke
     * @param array<string, mixed> $params Explicit parameter overrides
     *
     * @throws ContainerException If a required parameter cannot be resolved
     * @throws ReflectionException If reflection on the callable fails
     */
    #[Override]
    public function call(callable $callable, array $params = []): mixed
    {
        $reflection = CallableReflector::reflect($callable);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();

            // Explicit parameters take precedence
            if (isset($params[$name])) {
                $arguments[] = $params[$name];

                continue;
            }

            $type = $parameter->getType();

            // Try to resolve from container by type hint
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                if ($this->has($typeName)) {
                    $arguments[] = $this->get($typeName);

                    continue;
                }
            }

            // Fall back to default value
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            // Nullable parameters default to null
            if ($type !== null && $type->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw ContainerException::unresolvable(
                'call()',
                sprintf(
                    'Cannot resolve parameter "%s" for callable: no container binding and no default value',
                    $name,
                ),
            );
        }

        return $callable(...$arguments);
    }
}
