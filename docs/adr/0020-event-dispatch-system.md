# ADR-0020: Event Dispatch System

## Status

Accepted

## Context

Pulsar needs an event dispatch system that supports PSR-14 interoperability, priority-ordered listeners, compiled listener maps for production performance, module-scoped dispatch for boundary enforcement, storm protection against cascading dispatch loops, and metadata envelopes for audit trails - all without polluting domain event DTOs with infrastructure concerns.

In regulated domains, events carry compliance significance: security-sensitive actions must be auditable, event storms must be detectable before they exhaust resources, and module boundaries must be respected to maintain architectural integrity.

## Decision drivers

1. **PSR-14 compliance**: The dispatcher and listener provider must implement `Psr\EventDispatcher\EventDispatcherInterface` and `Psr\EventDispatcher\ListenerProviderInterface` for interoperability.
2. **Production performance**: Listener resolution must not involve reflection or directory scanning at runtime. Compiled listener maps are the production default.
3. **Module boundary enforcement**: Events dispatched within a module should be distinguishable from cross-module events (ADR-0002).
4. **Storm protection**: Cascading event dispatch (event A triggers event B triggers event A) must be detected and halted before resource exhaustion.
5. **Metadata without pollution**: Correlation IDs, timestamps, origin modules, and payload hashes must travel with events without adding properties to domain event classes.

## Decision

Implement `src/Event/` as a core event module with the following architecture:

### Dispatcher

`EventDispatcher` (internal) implements PSR-14 `EventDispatcherInterface` with these responsibilities:

1. Resolve listeners from the `ListenerProviderInterface`
2. Enforce envelope requirements (events annotated with `#[RequiresEnvelope]` must be dispatched via `EventEnvelope`)
3. Enter/leave the `StormGuard` around dispatch
4. Respect `StoppableEventInterface` for propagation control
5. Emit dispatch metrics (`pulsar_event_dispatched_total`, `pulsar_event_dispatch_depth`)

### Listener providers

Two implementations serve different runtime modes:

| Provider                   | Mode        | Behavior                                                                                      |
| -------------------------- | ----------- | --------------------------------------------------------------------------------------------- |
| `ListenerProvider`         | Development | Dynamic registration via `addListener()`/`addSubscriber()`. Priority-sorted on dispatch.      |
| `CompiledListenerProvider` | Production  | Read-only. Loads from a pre-sorted compiled map. Registration methods throw `EventException`. |

`CompiledListenerProvider` also implements `ListenerMetadataProviderInterface`, exposing pre-resolved metadata: envelope requirements (`#[RequiresEnvelope]`), storm overrides (`#[StormOverride]`), and listener module IDs - all computed at build time by `EventMapCompiler`.

Listener resolution supports class hierarchy: dispatching a `UserCreatedEvent` also triggers listeners registered for parent classes and implemented interfaces.

### Event envelope

`EventEnvelope` is a readonly value object wrapping event payload with metadata:

```php
final readonly class EventEnvelope
{
    public function __construct(
        public string $eventId,        // 128-bit random hex ID
        public string $eventType,      // Semantic event type (FQCN or domain name)
        public int $schemaVersion,     // Payload schema version
        public EventMetadata $metadata, // Correlation ID, timestamp, actor, tenant
        public array $payload,         // Serializable event data
        public string $payloadHash,    // SHA-256 of canonical payload
        public ?string $originModule,  // Source module ID
        public EventScope $scope,      // Internal or CrossModule
    ) {}
}
```

The payload hash is computed from canonical serialization: `eventType|schemaVersion|recursiveKsort(JSON(payload))`. This enables tamper detection and idempotency checking downstream.

### Module-scoped dispatch

`ModuleEventDispatcher` wraps the core dispatcher and stamps `originModule` on all envelopes dispatched through it. The core dispatcher then computes `EventScope` (Internal vs. CrossModule) by comparing the origin module against listener module IDs. This enables:

- Audit trails that show cross-module event flow
- Metrics segmented by module and scope
- Future enforcement of module boundary policies

### Storm protection

`StormGuard` provides two protection mechanisms:

1. **Depth ceiling**: Hard limit on total dispatch chain length (configurable via `StormProtectionConfig.maxDepth`). Prevents unbounded recursion regardless of event types.
2. **Loop detection**: Counts occurrences of the same event FQCN in the current chain. If an event appears `maxRepeatsPerEvent` times, dispatch is halted with `EventException`.

Events can override the depth ceiling via `#[StormOverride(maxDepth: 20)]` for known-deep dispatch chains (e.g., workflow engines).

### Attributes

| Attribute             | Purpose                                                                    |
| --------------------- | -------------------------------------------------------------------------- |
| `#[RequiresEnvelope]` | Event class must be dispatched via `EventEnvelope`, not plain `dispatch()` |
| `#[StormOverride]`    | Override `StormGuard` max depth for a specific event class                 |

## Alternatives considered

### Third-party event library

Rejected: external event dispatchers do not provide module-scoped dispatch, storm protection, or compiled listener maps. These are framework-level concerns that require deep integration with the container, module system, and observability stack.

### Mediator pattern

Rejected: mediator centralizes all handler resolution in a single class, making it difficult to scope dispatch to modules or enforce boundary policies. The listener provider pattern (PSR-14) provides better separation of concerns.

### Direct method calls (no event system)

Rejected: creates tight coupling between modules. Event-driven architecture enables loose coupling, audit trails, and extensibility - all critical for regulated domains where modules must remain independently deployable and auditable.

## Consequences

### Positive

- PSR-14 compliance enables interoperability with third-party event-aware libraries
- Compiled listener maps eliminate reflection from production dispatch paths
- Storm protection prevents cascading dispatch loops with configurable depth and loop detection
- Event envelopes carry metadata (correlation ID, origin module, payload hash) without polluting domain event DTOs
- Module scope computation enables per-module audit trails and future boundary enforcement

### Negative

- Dual listener provider implementations (dynamic and compiled) increase test surface
- Envelope-based dispatch adds overhead: SHA-256 hashing, JSON serialization for canonical payload, scope computation per dispatch
- `#[RequiresEnvelope]` enforcement adds a runtime check on every dispatch (optimized via pre-resolved metadata in compiled mode)

### Neutral

- Storm guard state is per-process - in persistent worker mode (ADR-0010), the guard resets between requests via `reset()`
- `EventMapCompiler` runs at build time and outputs a PHP array - no file format migration concerns

## Security impact

- `EventEnvelope.payloadHash` (SHA-256) enables tamper detection for events persisted to event stores or transmitted over network boundaries
- `#[RequiresEnvelope]` enforces that security-sensitive events always carry audit metadata (actor, tenant, correlation ID)
- Storm protection prevents resource exhaustion from malicious or buggy event loops
- `ReplAuditLogger` integration (ADR-0022) logs security events dispatched through the system

## Performance impact

- **Development mode**: Listener resolution involves sorting on every dispatch. Acceptable for development iteration speed.
- **Production mode**: `CompiledListenerProvider` is a single array lookup per event class (with class hierarchy cache). Listener instantiation is deferred to the container.
- **Storm guard**: O(n) scan of dispatch chain per `enter()` call, where n is current chain depth. Maximum chain depth is bounded by `maxDepth` (default: 10), making this effectively O(1).
- **Envelope hashing**: SHA-256 + JSON serialization adds ~10-50μs per envelope creation. Negligible for typical event volumes; high-throughput paths should use plain dispatch without envelopes.

## Migration / rollback plan

Additive change - introduces `src/Event/` as a new core module. To roll back: remove the module and inline direct method calls at dispatch sites. Event store data (if any) is application-owned and unaffected by framework rollback.

## Links

- ADR-0002: Module boundaries and `#[Internal]` namespace convention
- ADR-0007: In-house observability stack (metrics integration)
- ADR-0008: HMAC-chained tamper-evident audit logging
- ADR-0010: Persistent worker runtime and request sandbox (storm guard reset)
