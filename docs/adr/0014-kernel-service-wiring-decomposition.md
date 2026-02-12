# ADR-0014: Kernel Service-Wiring Decomposition

## Status

Accepted

## Context

The `Kernel` class grew to ~1,825 lines as new subsystems were added (security, auth, tenancy, feature flags, scheduler, queue, resilience, deploy, integrity, diagnostics). Each subsystem's service wiring (container registration, middleware attachment, route registration) was inlined as private methods in the Kernel. This made the Kernel difficult to navigate, test in isolation, and extend.

The Kernel's responsibilities had expanded far beyond orchestrating boot and request handling - it had become a monolithic composition root with deep coupling to every subsystem's initialization details.

Additionally, the post-audit review identified this as a maintainability and security concern: a single 1,800-line file increases the risk of merge conflicts, makes code review harder, and obscures the boot sequence.

## Decision drivers

1. **Maintainability** - Each subsystem's wiring logic should be self-contained and independently reviewable.
2. **Testability** - Wiring classes can be unit-tested without booting the full Kernel.
3. **Separation of concerns** - The Kernel should orchestrate the boot sequence, not implement every subsystem's initialization.
4. **Consistency** - All wiring classes follow the same interface and pattern.

## Decision

Extract each subsystem's service wiring from the Kernel into dedicated `ServiceWiringInterface` implementations in `src/Core/Wiring/`. The Kernel delegates to these classes in a deterministic sequence during boot.

### Interface

```php
#[Internal]
interface ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        Router $router,
    ): void;
}
```

### Wiring classes

Twenty wiring classes were extracted, each responsible for a single subsystem:

| Wiring Class             | Subsystem             |
| ------------------------ | --------------------- |
| `ConfigWiring`           | Configuration loading |
| `LoggingWiring`          | Logger + sinks        |
| `TracingWiring`          | Tracing spans         |
| `MetricsWiring`          | Metrics registry      |
| `RequestContextWiring`   | Request context       |
| `ErrorTrackingWiring`    | Error aggregation     |
| `ExceptionHandlerWiring` | Exception handling    |
| `SecurityWiring`         | Security services     |
| `AuthWiring`             | Authentication        |
| `DatabaseWiring`         | Database connections  |
| `TenancyWiring`          | Multi-tenancy         |
| `FeatureFlagWiring`      | Feature flags         |
| `SchedulerWiring`        | Task scheduler        |
| `ResilienceWiring`       | Circuit breakers      |
| `QueueWiring`            | Job queues            |
| `SupervisorWiring`       | Process supervisor    |
| `IntegrityWiring`        | File integrity        |
| `DeployWiring`           | Deploy readiness      |
| `RuntimeWiring`          | Runtime configuration |
| `DiagnosticsWiring`      | Diagnostics routes    |

### Kernel boot sequence

```php
private function wireServices(): void
{
    $wirings = [
        new ConfigWiring(),
        new LoggingWiring(),
        new TracingWiring(),
        // ... remaining wirings in dependency order
    ];
    foreach ($wirings as $wiring) {
        $wiring->wire($this->container, $this->configManager, $this->middleware, $this->router);
    }
}
```

The ordering is significant - it mirrors the original Kernel method call order to preserve boot-time dependency resolution.

## Alternatives considered

### Service providers with auto-discovery

Laravel-style service providers with `register()` / `boot()` lifecycle. Rejected: adds indirection and an auto-discovery phase that conflicts with Pulsar's explicit-over-magic principle. The wiring classes are deliberately listed in a hardcoded array - the boot order is a design decision, not a discovery artifact.

### Keep the monolithic kernel

Leave the Kernel as-is and rely on IDE folding/navigation. Rejected: 1,825 lines is well past the point where code review, merge conflict resolution, and onboarding become painful. The Kernel was the single largest file in the codebase by a wide margin.

## Consequences

### Positive

- Kernel reduced from ~1,825 to ~400 lines.
- Each wiring class is focused, reviewable, and testable in isolation.
- Adding a new subsystem requires adding one wiring class - no Kernel modification.
- Boot order is explicit and documented in the Kernel's wiring array.

### Negative

- Twenty new files in `src/Core/Wiring/`.
- Developers must understand the wiring interface to add new subsystems (low barrier - the pattern is simple).

### Neutral

- All wiring classes are `#[Internal]` - this is composition root infrastructure, not public API.
- Existing tests pass unchanged - this is a pure structural refactoring with no behavioral change.

## Security impact

None. The decomposition does not change any security behavior. All security-related wiring (encryption, CSRF, rate limiting, session management) is preserved exactly as implemented, just in dedicated `SecurityWiring` and `AuthWiring` classes rather than Kernel private methods.

## Performance impact

None. The wiring classes are instantiated once during boot. The object allocation overhead (20 lightweight readonly objects) is negligible compared to the IO-bound operations they perform (config loading, SQLite connection, middleware registration).

## Migration / rollback plan

This is an internal refactoring. No public API changed. Rollback: revert the commit and inline the wiring methods back into the Kernel.

## Links

- PR #30: Post-audit remediation
- ADR-0004: Extension-first architecture (wiring classes follow the same composition-root principle)
- ADR-0013: Boundary enforcement (wiring classes are `#[Internal]`)
