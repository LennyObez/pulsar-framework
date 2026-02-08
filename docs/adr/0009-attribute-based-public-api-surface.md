# ADR-0009: Attribute-Based Public API Surface (#[Api] / #[Internal])

## Status

Accepted

## Context

Frameworks struggle with API stability. Developers depend on classes, methods, and constants that the framework considers internal, then break on minor upgrades. Common mitigation strategies include:

- **Naming conventions** (`@internal` docblocks, `Internal` namespaces) — unenforced, ignored by IDEs and static analysis.
- **Separate packages** (public API in one package, implementation in another) — high overhead, complex dependency management.
- **Documentation-only** ("don't use classes in this namespace") — routinely violated.

PHP 8.0+ attributes provide a machine-readable mechanism that static analysis tools, IDEs, and CI can enforce.

## Decision

Pulsar uses two PHP attributes to classify every type in the framework:

- **`#[Api]`** (`Pulsar\Api\Api`) — marks a class, method, or class constant as part of the public API. Types with this attribute are covered by semantic versioning guarantees: breaking changes require a major version bump. Accepts an optional `since` parameter.
- **`#[Internal]`** (`Pulsar\Api\Internal`) — explicitly marks a type as internal implementation. Accepts an optional `reason` parameter. This attribute is optional — everything without `#[Api]` is internal by default.

### Enforcement

- **Public API snapshot.** `tools/api/generate-snapshot.php` produces a deterministic JSON snapshot of all `#[Api]`-annotated types. `PublicApiSnapshotTest` compares the current codebase against the snapshot and fails if the public API surface changed unexpectedly.
- **Architecture rules.** Deptrac configuration enforces that cross-module imports target only `#[Api]` types (see `tools/php/deptrac.yaml`).
- **RC policy.** During the RC phase, changes to `#[Api]` symbols require a changelog entry, a compatibility note, and updated tests. Additive changes are preferred; breaking changes require explicit sign-off.

### Visibility model

1. Type has `#[Api]` → public, semver-protected.
2. Type has `#[Internal]` → internal, may change in any minor release.
3. Type has neither → internal by default.
4. The attributes themselves (`Api`, `Internal`) are public API.

## Consequences

### Positive

- **Machine-readable contracts.** Static analysis, snapshot tests, and CI can enforce API stability automatically. No reliance on developer discipline alone.
- **Clear upgrade path.** Users know exactly which types are safe to depend on. Internal changes never require a major version bump.
- **Granular marking.** `#[Api]` can be applied to individual methods or constants, not just entire classes. A class can expose a public factory method while keeping its constructor internal.
- **Self-documenting.** Reading any class header immediately shows whether it is part of the public contract.

### Negative

- **Annotation overhead.** Every public type must be explicitly marked. Missing an `#[Api]` attribute means the type is internal by default — safe, but can surprise extension authors who expected a type to be public.
- **Snapshot maintenance.** The API snapshot must be regenerated when the public surface changes intentionally. This adds a step to the development workflow.

### Neutral

- **IDE support.** Current PHP IDEs do not natively distinguish `#[Api]` from `#[Internal]` in autocompletion. This may improve as tooling matures.
