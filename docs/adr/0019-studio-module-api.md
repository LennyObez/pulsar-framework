# ADR-0019: Studio Module Extension Surface

## Status

Accepted

## Context

Studio provides a development console with event collection, evidence chain, and diagnostics UI. As the framework grows, other extensions (Admin, ORM browser, queue monitor) need to contribute panels, pages, and navigation entries to the Studio UI.

Without a formal module API, each extension would need to directly modify Studio's routes and templates — creating tight coupling, ordering issues, and no clear contract for third-party extensions to follow.

Regulated environments require explicit, auditable registration of every capability an extension contributes. Ad-hoc route injection or template overriding does not meet this bar.

## Decision

Introduce a Studio Module API that lets extensions register self-contained UI modules within Studio. Key design choices:

- **`StudioModuleInterface`** (`#[Api]`). Extensions implement this contract to declare a module's identity, navigation entries, route prefix, and route registration callback. The `moduleId()` must match `[a-z0-9_-]+` to ensure URL-safe, collision-free identifiers.
- **`StudioModuleRegistryInterface`** (`#[Api]`). Public contract for registering and querying modules. Extensions interact only through this interface.
- **`StudioModuleRegistry`** (`#[Internal]`). In-memory implementation that validates module IDs (charset + uniqueness), detects route prefix collisions, and returns modules sorted by `navOrder()`.
- **`StudioNavEntry`** (`#[Api]`). Value object describing a single navigation item: label, href, icon, display order, and optional badge.
- **Studio provides layout/shell only.** Modules own their routes under `/studio/{moduleId}/` and render their own content. Studio provides the navigation chrome, authentication gate, and shared assets.
- **Registration via container.** The registry is created in Studio's `preBoot()` phase and bound to the container. Extensions register their modules during `boot()` by resolving `StudioModuleRegistryInterface`.

## Consequences

### Positive

- **Extension parity.** Third-party extensions register Studio modules through the same `#[Api]` contract as first-party code. No privileged access required.
- **Deterministic ordering.** Modules are sorted by `navOrder()`, giving predictable navigation layout regardless of extension boot order.
- **Fail-fast validation.** Invalid module IDs, duplicate registrations, and route prefix collisions are caught at registration time with clear error messages.
- **Auditable.** The registry provides a complete inventory of all registered Studio modules, satisfying compliance requirements for UI surface enumeration.

### Negative

- **Ceremony.** Even a simple Studio panel requires implementing `StudioModuleInterface` with all its methods. This is intentional — explicit is better than implicit in regulated environments.
- **Route ownership.** Modules must manage their own route registration. Studio does not provide automatic CRUD scaffolding or route generation.

### Neutral

- **No lazy loading.** All modules are registered eagerly during boot. For the expected number of Studio modules (typically under 20), this has negligible performance impact.
