# ADR-0013: Boundary Enforcement via API Interfaces

## Status

Accepted

## Context

ADR-0009 established `#[Api]` / `#[Internal]` attributes to classify public vs. internal types. However, enforcement was limited to snapshot tests — no automated tool prevented cross-module imports of `#[Internal]` types at development time.

A boundary-checking script (`scripts/boundary_check.php`) was introduced to scan `use` statements for cross-module imports of non-`#[Api]` types. The initial baseline identified 123 violations across 41 unique classes. These violations existed because many cross-module dependencies targeted concrete `#[Internal]` implementations rather than `#[Api]` contracts.

Three root causes drove the violations:

1. **Missing abstractions.** Internal classes (e.g., `FrameworkCache`, `Supervisor`, `ManifestBuilder`) had no corresponding `#[Api]` interface. Cross-module consumers had no choice but to import the concrete class.
2. **Dependency direction.** Some modules imported concrete implementations when an existing `#[Api]` interface was available (e.g., `Container` instead of `ContainerInterface`).
3. **Missing API attribution.** Stable types (value objects, exceptions, base classes) lacked `#[Api]` despite being genuine public API.

## Decision Drivers

1. **Enforce ADR-0009 at CI level.** Without tooling, attribute-based API classification is advisory only.
2. **Preserve implementation flexibility.** Consumers should depend on contracts, not concrete classes that may change.
3. **Minimize churn.** Prefer the narrowest fix for each violation — new interface only when abstraction is warranted.

## Decision

Resolve all 123 boundary violations using three strategies, applied per-class based on architectural fitness:

### Strategy 1: Introduce `#[Api]` interfaces (16 new interfaces)

For internal classes where cross-module consumers need only a subset of the public surface, create a narrow `#[Api]` interface and update imports. Concrete classes remain `#[Internal]` and implement the interface. DI bindings added in the composition root (Kernel).

Key interfaces introduced:

- `Core\KernelInterface` — stable subset (`boot`, `handle`, `run`, `shutdown`, `container`, `router`)
- `Cache\FrameworkCacheInterface` — cache read/write contract
- `Security\Crypto\EncryptorInterface`, `HmacInterface`, `KeyProviderInterface` — crypto contracts
- `Integrity\ManifestBuilderInterface`, `ManifestSignerInterface`, `ManifestVerifierInterface`
- `Supervisor\SupervisorInterface`, `PreflightCheck\PreflightRunnerInterface`
- `Deploy\DeployCheckRunnerInterface`
- `Resilience\HealthCheck\HealthCheckRunnerInterface`, `Repair\RepairRunnerInterface`
- `Observability\ErrorTracking\ErrorAggregatorInterface`, `Log\Sink\DeferredSinkInterface`
- `FeatureFlag\FlagEvaluationLogInterface`
- `Runtime\PersistentRuntimeFactoryInterface` — encapsulates runtime bootstrap internals

An adapter class (`HmacService`) was introduced for `HmacInterface` to avoid converting `Hmac`'s static API to instance methods.

### Strategy 2: Fix dependency direction (11 violations)

Where an `#[Api]` interface already existed, update imports to target the interface:

- `Container` → `ContainerInterface`
- `InMemorySpanCollector` → `SpanProcessorInterface`
- `ConfigManager` → `ConfigLoaderInterface`
- `W3CTraceContextParser` → new `TraceContextParserInterface`
- Route-cache DTOs: move reconstruction logic to Kernel (composition root, exempt from boundary rules)
- Runtime bootstrap: encapsulate `LeakDetector`, `RequestSandbox`, `RequestResetRegistry` behind `PersistentRuntimeFactoryInterface`

### Strategy 3: Promote stable types to `#[Api]` (15 types, 62 violations)

For types that are genuinely stable public API — value objects, exceptions, base classes, and entry-point orchestrators — add or change the attribute to `#[Api]`:

- Value objects: `LabelSet`, `ErrorEvent`, `TraceId`, `Statement`, `Transaction`
- Exception: `CacheException`
- Enum: `ManifestFormat`
- Base class: `Console\Command` (extension point for all CLI commands)
- Migration: `MigrationRunner`, `MigrationRepository`
- Utilities: `SensitiveDataScrubber`, `AtomicFileWriter`
- Multi-tenancy: `TenantContext`
- Scheduler: `Scheduler`, `JobRegistry`

## Alternatives Considered

### Mark all 41 classes as `#[Api]`

Rejected. This would freeze implementation details (constructors, internal methods) and prevent future refactoring. Many classes have large internal surfaces that should remain changeable.

### Suppress violations in baseline permanently

Rejected. A permanent baseline defeats the purpose of boundary enforcement. The baseline should converge to zero.

### Use namespace-based boundaries (e.g., `Internal\` sub-namespaces)

Rejected. Would require mass-renaming and lose the granularity of attribute-level marking. ADR-0009 already chose attributes over namespaces.

## Consequences

### Positive

- **Zero boundary violations.** CI enforces that all cross-module imports target `#[Api]` types.
- **Decoupled modules.** Consumers depend on narrow interfaces, not implementation details.
- **Safe refactoring.** Internal classes can change constructors, add parameters, or restructure without affecting cross-module consumers.
- **Composition root pattern.** Complex wiring (runtime bootstrap, route-cache reconstruction) is centralized in Kernel, keeping module code clean.

### Negative

- **19 new files.** 16 interfaces + 1 adapter + 1 factory + 1 factory interface add to the codebase. Each is small (typically 5-15 methods) and follows existing patterns.
- **DI registration overhead.** ~16 new container bindings in Kernel. Negligible runtime cost (one-time at boot).

### Neutral

- **API snapshot grows.** The public API snapshot includes the new interfaces. This is intentional — they are the stable contracts.
- **Existing extension code.** Extensions already importing concrete classes will need to update imports. This is a one-time migration during the RC phase.

## Field Report

_Optional. Document operational experience that validates or challenges this decision. Add entries as they accumulate._

- **rc.6 – rc.10** | PR #29, boundary enforcement rollout: Initial scan identified 123 violations across 41 classes. The three-strategy remediation — 16 new interfaces, 11 dependency direction fixes, 15 type promotions — achieved zero violations within a single release cycle. Post-enforcement, two subsequent refactors (cache subsystem and observability pipeline) confirmed the value: internal class constructors were restructured without breaking any cross-module consumer. CI boundary checks caught three accidental concrete imports during code review, preventing regressions before merge. Measurable improvement in refactoring safety and developer confidence when modifying internal implementations.

## Security Impact

None. The change is purely structural (import paths and DI wiring). No changes to authentication, authorization, encryption, or data handling. Crypto contracts (`EncryptorInterface`, `HmacInterface`, `KeyProviderInterface`) expose the same operations as the concrete classes — no new attack surface.

## Performance Impact

None. Interface dispatch in PHP adds no measurable overhead. DI container bindings are resolved at boot time, not on the hot path. The `HmacService` adapter adds one static method delegation per call — negligible compared to the sodium operations it wraps.

## Migration / Rollback Plan

**Adoption:** Extensions update `use` statements from concrete classes to interfaces (e.g., `use Pulsar\Core\Kernel` → `use Pulsar\Core\KernelInterface`). Constructor signatures that accepted concrete types should accept the interface instead.

**Rollback:** Remove interface files, revert DI bindings, restore concrete imports. The boundary baseline would need to be re-populated with the original violations. No data migration or schema changes involved.

## Links

- ADR-0009: Attribute-Based Public API Surface (`#[Api]` / `#[Internal]`)
- PR #29: Boundary enforcement implementation
- `scripts/boundary_check.php`: Boundary enforcement scanner
- `tools/php/boundary-baseline.json`: Violation baseline (target: empty)
