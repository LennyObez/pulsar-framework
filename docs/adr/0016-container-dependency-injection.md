# ADR-0016: Container Dependency Injection

## Status

Accepted

## Context

Pulsar requires a dependency injection container that can be used across all modules without introducing a third-party runtime dependency. The container must support multiple service lifetimes (singleton, transient, request-scoped, tenant-scoped), contextual bindings for polymorphic resolution, lazy proxy generation for deferred instantiation, decorator chains for cross-cutting concerns, and a compiler pass pipeline for build-time optimization.

Existing solutions couple frameworks to external libraries, limit lifetime control to singleton/transient, or rely on runtime auto-discovery (annotation scanning on every request). For a framework targeting regulated, mission-critical domains, the container must be deterministic, auditable, and fast — with compile-time optimization as the default production path.

## Decision Drivers

1. **Zero third-party runtime**: The container is the composition root — external dependencies here propagate everywhere.
2. **Multi-tenant support**: Banking and healthcare applications require tenant-isolated service instances with strict scope boundaries.
3. **Compile-time optimization**: Production deployments must skip reflection by using cached resolution hints.
4. **Explicit wiring**: No magic auto-discovery. All bindings are explicit or registered through deferred providers.
5. **Decorator-friendly**: Cross-cutting concerns (caching, logging, authorization) attach via priority-ordered decorator chains, not inheritance.

## Decision

Implement `src/Container/` as a self-contained DI module with the following architecture:

### Container Core

`Container` implements both PSR-11 (`ContainerInterface`) and `AdvancedContainerInterface`. The minimal PSR-11 surface is stable for extension authors; the advanced interface exposes tags, scopes, decorators, and compilation for framework internals.

```php
interface AdvancedContainerInterface extends ContainerInterface
{
    public function bindWithLifetime(string $id, callable|string $concrete, Lifetime $lifetime): void;
    public function tag(string $id, string $tagName, int $priority = 0, array $attributes = []): void;
    public function decorate(string $id, string|callable $decorator, int $priority = 0): void;
    public function when(string $consumer): ContextualBindingBuilder;
    public function processCompilerPasses(PassRunner $runner): void;
}
```

### Service Lifetimes

| Lifetime       | Behavior                                   | Eviction                             |
| -------------- | ------------------------------------------ | ------------------------------------ |
| `Singleton`    | Resolved once, cached for process lifetime | Never (until process ends)           |
| `Transient`    | New instance on every `get()` call         | Immediate (no caching)               |
| `RequestScope` | One instance per HTTP request              | `endRequestScope()` between requests |
| `TenantScope`  | One instance per tenant context            | `endTenantScope()` on tenant switch  |

Scoped lifetimes are managed by `ScopeManager`, which enforces scope widening rules — a `RequestScope` service cannot depend on a `TenantScope` service, preventing accidental tenant data leakage.

### Contextual Bindings

```php
$container->when(PaymentController::class)
    ->needs(GatewayInterface::class)
    ->give(StripeGateway::class);
```

Contextual bindings are checked during autowiring before falling back to the global binding map. This enables polymorphic resolution without service locator patterns.

### Lazy Proxy Generation

`LazyServiceFactory` uses `ReflectionClass::newLazyProxy()` (PHP 8.4+) to create ghost objects that defer construction until first property or method access. Services opt in via the `lazy` flag on `ServiceDefinition`. No code generation or cache files — native PHP lazy objects.

### Compiler Pass Pipeline

`ContainerBuilder` + `PassRunner` process `ServiceDefinition` instances before the container is frozen:

| Pass                        | Purpose                                                            |
| --------------------------- | ------------------------------------------------------------------ |
| `AutoTagPass`               | Auto-tags services implementing known interfaces                   |
| `ResolveTaggedIteratorPass` | Replaces `TaggedIterator` placeholders with resolved service lists |
| `ValidateDecoratorPass`     | Validates decorator chains reference registered services           |
| `ValidateLifetimesPass`     | Detects scope widening violations at build time                    |
| `OptimizePass`              | Strips metadata not needed at runtime                              |

### Resolution Hints Cache

In production, `resolutionHints` — a pre-computed map of `class-string → constructor parameter types` — bypasses reflection entirely. Hints are fallible: if a hint fails at resolution time, the container silently falls back to reflection. This makes cache invalidation safe — stale hints degrade to slower resolution, never to errors.

## Alternatives Considered

### Third-party DI container

Rejected: adds a transitive dependency to every Pulsar application. Version conflicts between framework and application dependencies become the framework's problem. The container is too central to delegate to an external library.

### Service locator pattern

Rejected: violates explicit dependency principle. Services pull dependencies from a global registry, making dependency graphs invisible to static analysis and scope enforcement impossible.

### Pure manual wiring (no autowiring)

Rejected: prohibitive DX cost. Every new constructor parameter requires updating wiring code. Autowiring with explicit overrides (contextual bindings, tagged iterators) provides the right balance of convenience and control.

## Consequences

### Positive

- Zero external dependencies in the composition root
- Four lifetime types cover single-tenant, multi-tenant, and stateless use cases
- Compiler passes catch misconfiguration (scope widening, broken decorators) at build time rather than runtime
- Resolution hints eliminate reflection from production hot paths
- Contextual bindings enable clean polymorphism without factory proliferation

### Negative

- Custom container requires maintaining PSR-11 compliance and edge-case handling internally
- Lazy proxy generation depends on PHP 8.4+ `ReflectionClass::newLazyProxy()` — no fallback for older runtimes
- Compiler pass ordering is implicit (array order) — passes must be registered in dependency order

### Neutral

- `ScopeManager` is injected at Kernel boot and null in test/simple usage — scoped lifetimes silently degrade to transient when no scope manager is present
- Deferred providers are resolved on first `get()` — provider registration order does not affect resolution correctness

## Security Impact

The container holds references to all application services, including security-sensitive ones (crypto keys, audit loggers, auth guards). Mitigations:

- `ReadOnlyContainer` proxy (used by REPL safe mode, ADR-0022) prevents runtime binding mutation
- No service locator access from user-facing code — constructor injection only
- Scope enforcement prevents tenant data leakage through `ScopeWideningException`
- `#[Internal]` on `Container` class — extensions depend on the interface, not the implementation

## Performance Impact

- **Cold start**: Reflection-based autowiring runs once per service. Compiler passes are O(n) in definition count.
- **Warm start (production)**: Resolution hints bypass reflection entirely. `get()` for singletons is a single array lookup after first resolution.
- **Memory**: Singleton instances are cached for process lifetime. Scoped instances are evicted at scope boundaries. Transient instances are not cached.

## Migration / Rollback Plan

Additive change — introduces `src/Container/` as a new core module. To roll back: revert to the previous minimal container implementation. No data migrations required. All container bindings are defined in code (wiring classes), not persisted.

## Links

- ADR-0002: Module boundaries and `#[Internal]` namespace convention
- ADR-0009: `#[Api]` attribute-based public API surface
- ADR-0010: Persistent worker runtime and request sandbox (scoped lifetime motivation)
- ADR-0014: Kernel service wiring decomposition
