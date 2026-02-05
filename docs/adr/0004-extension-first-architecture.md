# ADR-0004: Extension-First Architecture with Manifest-Driven Lifecycle

## Status

Accepted

## Context

PHP frameworks typically use service providers (Laravel), bundles (Symfony), or modules (Laminas) to organize optional functionality. These approaches have common issues:

- **Privileged internals.** Framework-provided features often use internal APIs unavailable to third-party code, creating a two-tier ecosystem.
- **Implicit registration.** Auto-discovery and convention-based loading make it hard to reason about what is active and in what order.
- **No capability declaration.** Packages declare dependencies in `composer.json` but not what framework features they provide (routes, commands, middleware, migrations).

Pulsar targets regulated domains where auditability, determinism, and explicit configuration are requirements — not preferences.

## Decision

Adopt an extension-first architecture where all optional functionality — including first-party features like Studio, Payments, and Example — uses the same public extension API as third-party code.

Key design choices:

- **`pulsar.json` manifest.** Every extension declares its identity, version, capabilities (services, routes, commands, middleware, migrations), framework compatibility, and dependencies on other extensions.
- **Four-phase lifecycle.** Extensions progress through Register → PreBoot → Boot → PostBoot. Each phase is a separate pass over all extensions in dependency-resolved order. All registrations complete before any preBoot runs; all preBoot completes before any boot runs. This guarantees that preBoot can safely resolve any service registered by any extension. Interfaces: `ExtensionInterface` (register + boot), `PreBootExtensionInterface` (optional preBoot), `PostBootExtensionInterface` (optional postBoot).
- **No privileged access.** First-party extensions (`extensions/studio/`, `extensions/payments/`) use `ExtensionInterface` and lifecycle hooks identically to third-party code. There are no internal backdoors.
- **Deterministic ordering.** Extensions are topologically sorted by their declared dependencies via `ExtensionBootstrap`. Circular dependencies are detected and rejected at validation time.
- **State machine enforcement.** The `ExtensionLifecycle` enum tracks each extension's state (Discovered → Validated → Registered → Booted → Failed). Invalid transitions are rejected.
- **Capability declaration.** Manifests declare provided capabilities: services, routes, commands, middleware, and migrations. This enables auditing and static analysis of what each extension contributes.

## Consequences

### Positive

- **Auditability.** `pulsar.json` manifests provide a complete, machine-readable inventory of what each extension does. Compliance teams can audit without reading source code.
- **Deterministic boot.** The same extensions with the same manifests always boot in the same order. No ordering surprises.
- **Third-party parity.** Extension authors have the same capabilities as the framework team. No "blessed" extensions with special access.
- **Fail-fast validation.** Version incompatibility, missing dependencies, and circular references are caught before any code executes.

### Negative

- **Manifest overhead.** Every extension requires a `pulsar.json` even for trivial functionality. This is more ceremony than a single service provider class.
- **No auto-discovery.** Extensions must be explicitly listed or discovered from known directories. There is no Composer plugin that automatically activates packages.
- **Migration from other frameworks.** Developers coming from Laravel or Symfony must learn a different extension model.

### Neutral

- **Scaffold command available.** `php bin/pulsar make:extension` generates the boilerplate directory structure and manifest, reducing the ceremony cost.

## Field Report

_Optional. Document operational experience that validates or challenges this decision. Add entries as they accumulate._

- **rc.1 – rc.10** | Internal development: Manifest overhead proved manageable in practice. The `php bin/pulsar make:extension` scaffold command generates the full `pulsar.json` and directory structure in seconds, reducing ceremony to a one-time cost per extension. Deterministic boot ordering has been the highest-value payoff — dependency issues surface immediately at validation time rather than manifesting as subtle runtime bugs. During the rc cycle, topological sort caught three circular dependency attempts before any code executed, saving significant debugging time.
