# Event system

## Contents

- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Envelope Dispatch](#envelope-dispatch)
- [Enforcing Envelope Dispatch](#enforcing-envelope-dispatch)
- [Storm Protection](#storm-protection)
- [Module Scope](#module-scope)
- [Listener Priority](#listener-priority)
- [Stoppable Events](#stoppable-events)
- [Compiled Event Map](#compiled-event-map)
- [Observability](#observability)
- [Extension Ports (Interface-Only)](#extension-ports-interface-only)
- [Architecture](#architecture)

Pulsar provides a PSR-14 compatible event dispatcher with envelope-based dispatch, storm protection, module scope tracking, and a compiled event map for zero-reflection production performance.

## Quick start

### Dispatching events

```php
use Pulsar\Event\EventDispatcherInterface;

// Plain PSR-14 dispatch - any object is an event
$dispatcher->dispatch(new UserRegistered($userId));
```

### Registering listeners

```php
use Pulsar\Event\ListenerProviderInterface;

$provider->addListener(
    UserRegistered::class,
    function (UserRegistered $event): void {
        // handle the event
    },
    priority: 10,
);
```

### Using subscribers

```php
use Pulsar\Event\EventSubscriberInterface;

final class UserEventSubscriber implements EventSubscriberInterface
{
    public function getSubscribedEvents(): array
    {
        return [
            UserRegistered::class => ['onUserRegistered', 10],
            UserDeleted::class => ['onUserDeleted', 0],
        ];
    }

    public function onUserRegistered(UserRegistered $event): void { /* ... */ }
    public function onUserDeleted(UserDeleted $event): void { /* ... */ }
}

$provider->addSubscriber(new UserEventSubscriber());
```

## Configuration

Configuration lives in `config/event.php`:

```php
return [
    'enabled' => true,

    'storm_protection' => [
        'max_depth' => 32,
        'loop_detection' => true,
        'max_repeats_per_event' => 3,
    ],
];
```

| Key                                      | Default | Description                                            |
| ---------------------------------------- | ------- | ------------------------------------------------------ |
| `enabled`                                | `true`  | Master switch for the event system                     |
| `storm_protection.max_depth`             | `32`    | Maximum dispatch chain depth before throwing           |
| `storm_protection.loop_detection`        | `true`  | Enable re-entrant dispatch counting                    |
| `storm_protection.max_repeats_per_event` | `3`     | Max times the same event class can appear in one chain |

Override with environment variables: `EVENT_ENABLED`.

## Envelope dispatch

For events requiring audit trails, integrity verification, or cross-module scope tracking, wrap them in an `EventEnvelope`:

```php
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;

$envelope = EventEnvelope::wrap(
    eventType: 'user.registered',
    schemaVersion: 1,
    payload: ['user_id' => $userId, 'plan_tier' => 'premium'],
    metadata: new EventMetadata(
        correlationId: $correlationId,
        causationId: $causationId,
    ),
);

$result = $dispatcher->dispatchEnvelope($envelope);
```

### Envelope fields

| Field           | Type            | Description                                                       |
| --------------- | --------------- | ----------------------------------------------------------------- |
| `eventId`       | `string`        | Unique 32-hex-char identifier (cryptographically random)          |
| `eventType`     | `string`        | Semantic event name (e.g., `user.registered`)                     |
| `schemaVersion` | `int`           | Schema version for payload evolution                              |
| `metadata`      | `EventMetadata` | Correlation ID, timestamps, custom headers                        |
| `payload`       | `array`         | Key-value event data                                              |
| `payloadHash`   | `string`        | SHA-256 hash of canonical payload (tamper detection)              |
| `originModule`  | `?string`       | Module that dispatched the event (set by `ModuleEventDispatcher`) |
| `scope`         | `EventScope`    | `Internal` or `CrossModule` (computed from listener metadata)     |

### Metadata fields

| Field           | Type                | Description                                          |
| --------------- | ------------------- | ---------------------------------------------------- |
| `correlationId` | `CorrelationId`     | Trace correlation identifier for the request context |
| `causationId`   | `CausationId`       | Identifier of the command/event that caused this one |
| `actor`         | `?string`           | Authenticated principal (user ID, service name)      |
| `tenantId`      | `?string`           | Tenant identifier for multi-tenant deployments       |
| `occurredAt`    | `DateTimeImmutable` | Timestamp when the event was created                 |
| `attributes`    | `array`             | Arbitrary key-value pairs for custom headers         |

### Payload hash integrity

The `payloadHash` is computed from `eventType`, `schemaVersion`, and recursively key-sorted JSON payload. The `originModule` and `scope` fields are **not** included in the hash - they are routing metadata, not semantic payload.

```php
// Verify integrity after deserialization
$recomputed = EventEnvelope::wrap(
    $envelope->eventType,
    $envelope->schemaVersion,
    $envelope->payload,
    $envelope->metadata,
);
assert($recomputed->payloadHash === $envelope->payloadHash);
```

## Enforcing envelope dispatch

For compliance-critical events that must always carry metadata and integrity hashes, mark them with `#[RequiresEnvelope]` or implement `EnvelopeRequiredEvent`:

```php
use Pulsar\Event\Attribute\RequiresEnvelope;

#[RequiresEnvelope]
final readonly class PaymentProcessed
{
    public function __construct(
        public string $transactionId,
        public int $amountCents,
    ) {}
}

// This throws EventException::envelopeRequired():
$dispatcher->dispatch(new PaymentProcessed($txId, 5000));

// This works correctly:
$envelope = EventEnvelope::wrap('payment.processed', 1, [...], $metadata);
$dispatcher->dispatchEnvelope($envelope);
```

Both mechanisms (`#[RequiresEnvelope]` attribute and `EnvelopeRequiredEvent` interface) are equivalent. The attribute is preferred for new code.

## Storm protection

The event system guards against runaway cascading dispatches (event A triggers event B which triggers event A again).

### Depth limiting

The total dispatch chain depth is capped at `max_depth` (default 32). Any dispatch that exceeds this ceiling throws `EventException::stormDetected()`.

### Loop detection

When `loop_detection` is enabled, the dispatcher tracks every event class dispatched within the current chain. If the same event class appears `max_repeats_per_event` times (default 3), it throws `EventException::loopDetected()`.

This is count-based, not adjacency-based - re-entrant dispatch of the same event type is allowed up to the threshold.

### Per-event overrides

Events that legitimately need deeper chains can override the depth ceiling:

```php
use Pulsar\Event\Attribute\StormOverride;

#[StormOverride(maxDepth: 64)]
final readonly class RecursiveTreeEvent
{
    // Allowed to dispatch up to depth 64 instead of the global 32
}
```

`#[StormOverride]` overrides `maxDepth` only. It does not affect `maxRepeatsPerEvent`.

## Module scope

The `ModuleEventDispatcher` wraps the core dispatcher and stamps each envelope with its `originModule`. The inner dispatcher then computes scope by comparing the origin module against the modules of all registered listeners:

- **Internal**: All listeners belong to the same module as the origin.
- **CrossModule**: At least one listener belongs to a different module.

Scope is observable via `$envelope->scope` and emitted in metrics. It enables compliance monitoring of cross-boundary event flows.

## Listener priority

Listeners are sorted by:

1. **Priority** (descending - higher numbers run first)
2. **Registration sequence** (ascending - first registered wins ties)
3. **FQCN** (alphabetical - deterministic tie-breaking)

```php
// Runs first (priority 20)
$provider->addListener(OrderPlaced::class, $securityCheck, priority: 20);
// Runs second (priority 10)
$provider->addListener(OrderPlaced::class, $inventoryUpdate, priority: 10);
// Runs third (priority 0 = default)
$provider->addListener(OrderPlaced::class, $sendConfirmation);
```

## Stoppable events

PSR-14 stoppable events are supported. If an event implements `Psr\EventDispatcher\StoppableEventInterface` and `isPropagationStopped()` returns `true`, remaining listeners are skipped:

```php
use Psr\EventDispatcher\StoppableEventInterface;

final class CancellableEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stop(): void { $this->stopped = true; }

    public function isPropagationStopped(): bool { return $this->stopped; }
}
```

## Compiled event map

For production, `pulsar optimize` compiles all registered listeners into a static PHP array. This eliminates runtime reflection and sorting:

```bash
php bin/pulsar optimize
```

The compiled event map includes pre-resolved metadata:

- Listener class and method per event
- Priority ordering (pre-sorted)
- `#[RequiresEnvelope]` flags
- `#[StormOverride]` values
- Module IDs for scope computation

### Naming discipline lint

During compilation, event class names are validated against naming rules:

- **Max length**: 255 characters per FQCN
- **Allowed charset**: ASCII alphanumeric, `\`, `_` only
- **PII rejection**: no emails (`@`), UUIDs, digit runs >8 characters

Violations throw `EventException::invalidEventClassName()` and halt the build. This prevents cardinality explosion in observability metrics.

## Observability

The event dispatcher emits metrics when a `MetricRegistry` is available:

| Metric                               | Type    | Labels                           | Description                  |
| ------------------------------------ | ------- | -------------------------------- | ---------------------------- |
| `pulsar_event_dispatched_total`      | Counter | `event_class`, `module`, `scope` | Events dispatched            |
| `pulsar_event_dispatch_depth`        | Gauge   | `event_class`                    | Current dispatch chain depth |
| `pulsar_event_storm_prevented_total` | Counter | `event_class`                    | Storm protection triggers    |

See [OpenTelemetry Integration](opentelemetry.md) for distributed tracing of event flows.

## Extension ports (interface-only)

Three port interfaces define extension points for persistence and serialization adapters. These are interface-only contracts - no built-in implementations ship with the framework:

| Port              | Pattern              | Methods                                         |
| ----------------- | -------------------- | ----------------------------------------------- |
| `OutboxPort`      | Transactional outbox | `store()`, `markPublished()`, `pendingEvents()` |
| `EventStorePort`  | Event sourcing       | `append()`, `eventsFor()`                       |
| `EventSerializer` | Serialization        | `serialize()`, `deserialize()`                  |

## Architecture

### Public API (`src/Event/`)

All types under `src/Event/` (except `Internal/`) carry `#[Api(since: '1.0.0')]` and are part of the stable public surface.

### Internal implementation (`src/Event/Internal/`)

All concrete implementations live under `src/Event/Internal/` and carry `#[Internal]`. They must not be imported by extensions or application code - wire through the container.

| Class                      | Responsibility                                         |
| -------------------------- | ------------------------------------------------------ |
| `EventDispatcher`          | Core dispatch loop, storm guard, metrics, scope        |
| `ListenerProvider`         | Priority-sorted registry with module IDs               |
| `CompiledListenerProvider` | Read-only provider from compiled event map             |
| `ModuleEventDispatcher`    | Stamps `originModule` on envelopes                     |
| `EventMapCompiler`         | Build-time compiled event map generation + naming lint |
| `StormGuard`               | Depth tracking + loop detection                        |

### Wiring

`EventWiring` registers all event services in the container during kernel boot (after `RequestContextWiring`, before `ErrorTrackingWiring`). It reads `EventConfig` from the config repository and wires the dispatcher, listener provider, and storm guard.
