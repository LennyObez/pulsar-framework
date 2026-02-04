# ADR-0002: Modular Monolith with Vertical Slices and Ports/Adapters

## Status

Accepted

## Context

Pulsar is an HMVC framework targeting regulated, mission-critical domains. As the framework approaches 1.0.0 GA, we need to formalize module boundaries, enforce isolation, and establish clear conventions for organizing business logic within extensions.

Key observations:

- The framework already uses single-level Route-to-Handler-to-Response dispatch (no subrequest/hierarchical dispatch exists).
- Extensions like Payments already have clean contract interfaces, immutable domain models, and orchestrator patterns (Gateway, Processor).
- There is no formal convention for separating public API from internal implementation details.
- Business logic in orchestrators mixes multiple concerns (idempotency, metrics, audit, provider calls) in monolithic methods.

## Decision

Adopt a **Modular Monolith** architecture using **Vertical Slices** for business logic and **Ports/Adapters** for infrastructure isolation.

### Directory Conventions

Each extension follows a standardized directory structure:

- **`Contracts/`** — Public port interfaces marked with `#[Api]`. This is the extension's public contract.
- **`Domain/`** — Value objects, entities, and enums. Public types marked with `#[Api]`.
- **`Config/`** — Configuration DTOs. Public types marked with `#[Api]`.
- **`Exception/`** — Exception classes. Public types marked with `#[Api]`.
- **`Features/`** — Vertical slices. Each slice contains a Handler, Request DTO, and Result DTO. Internal by default.
- **`Internal/Infrastructure/`** — Adapter implementations of port interfaces. Marked with `#[Internal]`.
- **`Internal/Support/`** — Internal utilities. Marked with `#[Internal]`.
- **`Gateway/`** — Public orchestrator facades that delegate to slices. "Gateway" in this context means "use-case facade" (not a payment/API gateway). It is the entry point that external consumers call, delegating internally to one or more slice handlers.

### Visibility Model

- Types marked with `#[Api]` are part of the public contract and follow SemVer stability guarantees.
- Types without `#[Api]` are internal by default and may change without notice.
- Types marked with `#[Internal]` are explicitly private implementation details.
- External consumers must only depend on `Contracts/` interfaces, `Domain/` types, and `Config/` DTOs (which are also `#[Api]`-marked and semver-stable).

### Vertical Slice Structure

Each use case with cross-cutting orchestration (idempotency, metrics, audit, provider calls) is extracted into a self-contained slice:

```
Features/<UseCaseName>/
├── <UseCaseName>Handler.php    # Orchestration logic
├── <UseCaseName>Request.php    # Immutable input DTO
└── <UseCaseName>Result.php     # Immutable output DTO
```

Gateways delegate to slice handlers, providing backward-compatible method signatures.

### Port/Adapter Separation

Interfaces in `Contracts/` define what the module needs (ports). Implementations in `Internal/Infrastructure/` provide concrete adapters. The service provider resolves adapters based on configuration.

## Consequences

### Positive

- **Clear module boundaries.** Public API is explicit and enforceable. Internal changes don't break consumers.
- **Testable business logic.** Slices are self-contained units with clear inputs/outputs. Handler tests don't need HTTP infrastructure.
- **Swappable infrastructure.** Adapters can be replaced without touching business logic. Configuration-driven adapter selection.
- **Incremental migration.** Extensions can adopt the pattern gradually. Gateways delegate to slices progressively.
- **Consistent conventions.** All extensions follow the same structure, reducing cognitive load.

### Negative

- **More files per use case.** Each slice adds 3 files (Handler + Request + Result) compared to a single method on an orchestrator.
- **Initial migration effort.** Existing extensions need restructuring to adopt the conventions.
- **Handler dependency surfaces.** Initial extraction carries all orchestrator dependencies. These can be narrowed over time.

### Neutral

- **No hierarchical dispatch.** This decision formally documents that Pulsar uses single-level dispatch. This is a hard constraint, not a limitation.
- **Slice granularity is a judgment call.** Simple reads and trivial delegations can remain on orchestrators. The threshold is cross-cutting concerns. Examples of when to extract a slice: (1) a payment charge flow that coordinates idempotency checks, provider API calls, metrics emission, and audit logging; (2) a user registration flow that validates input, hashes credentials, sends a welcome email, and emits domain events. A simple "get user by ID" lookup does not warrant a slice.

## Migration Notes

The Payments extension is the first extension to adopt this architecture and serves as the reference implementation. Other extensions should follow the same patterns when they are next modified.

### For consumers

- Depend on `Contracts/` interfaces, not concrete classes.
- Use `PaymentGatewayInterface` instead of `PaymentGateway` directly.
- Never import from `Internal/` or `Features/` namespaces.

### Timeline

- **1.0.0-rc.x**: Payments extension restructured as exemplar.
- **1.0.0 GA**: Architecture conventions documented and stable.
- **Post-1.0**: Other extensions migrate to the pattern incrementally. CI enforcement (Deptrac + architecture tests) to be added in a future plan.
