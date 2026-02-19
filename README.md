# Pulsar Framework

<div align="center">
  <!-- Logo placeholder intentionally omitted until branding is finalized -->
  <h3>Next-generation PHP 8.5+ HMVC framework for security-critical, regulated systems</h3>
</div>

<div align="center">

[![PHP Version](https://img.shields.io/badge/php-8.5%2B-blue.svg)](https://www.php.net/)
[![CI](https://github.com/LennyObez/pulsar-framework/actions/workflows/ci.yml/badge.svg)](https://github.com/LennyObez/pulsar-framework/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-green.svg)](LICENSE)

</div>

> **Status:** Release Candidate (1.0.0-rc.11). The `#[Api]`-marked surface is SemVer-stable; non-`#[Api]` internals may change until 1.0.0.
> Track milestones in [`ROADMAP.md`](ROADMAP.md).

## Who this is for

Pulsar is built for teams that need:

- Audit trails, tamper-evident logging, and compliance-mappable controls out of the box
- Deterministic boot pipelines and explicit module boundaries (no auto-wiring surprises)
- A framework that ships its own observability stack (no vendor lock-in)
- PHP 8.5+ with strict typing, static analysis at max level, and performance budgets in CI

## Who this is _not_ for

- Teams looking for a batteries-included CMS or admin panel (Pulsar is a framework, not a product)
- Projects that need broad community plugin ecosystems today (RC phase, ecosystem is small)
- Rapid prototyping where convention-over-configuration is preferred (Pulsar is explicit by design)

## Why Pulsar

Pulsar targets teams building **mission-critical applications** where you need:

- **Deterministic architecture** (explicit boundaries, predictable boot pipeline)
- **Extension-first design** (stable hooks, compatibility checks, versioned lifecycle)
- **Measurable performance** (bench harness + budgets compared against stored baselines in CI; blocks merges when signal is stable, otherwise advisory with artifacts)
- **Security by default** (secure sessions, CSRF, headers, encryption, auditability)
- **Regulated-domain readiness** (documentation mapping features to compliance controls)

## Regulated industries focus

Pulsar is designed for regulated domains such as:

- Banking & payments (PSD2, PCI DSS, NIS2)
- Legal & e-signature workflows (GDPR, eIDAS)
- Medical & healthcare ecosystems (MDR, HL7/FHIR, ISO 13485)

> Important: Pulsar does **not** claim certification by itself.
> It provides secure defaults, auditability, and documentation that maps framework capabilities to compliance controls. Final compliance always depends on how each product is implemented and operated.

## Core principles

- **Lean hot path**: fast core with minimal overhead on the request path (bootstrap → route → handler → response)
- **No hidden magic**: explicit configuration, explicit dependencies, type-safe APIs
- **Compile-ready DI**: reduce runtime reflection overhead where possible
- **HMVC done strictly**: modules are first-class boundaries with clear contracts
- **DX as a feature**: CLI, scaffolding, diagnostics, and quality gates are part of the product

## Features (roadmap-driven)

### 1) Deterministic HMVC Modules

- Canonical module layout and discovery
- Explicit module boundaries and contracts
- Versioned module lifecycle

### 2) Extension System

- `pulsar.json` manifest
- Discover → validate → register → boot
- Stable hooks: DI bindings, routes, console commands, migrations, assets
- Compatibility validation and deprecation strategy

### 3) Pulsar Studio - Studio Console

No dependency on external monitoring vendors.

- Structured logs + audit logging subsystem
- Metrics collector + exporters (OpenMetrics text exposition format)
- Tracing: spans, context propagation, sampling rules
- Error reporting: grouping, fingerprints, local viewer UI

### 4) Security Baseline (default-on)

- Session hardening, CSRF protection, security headers, rate limiting
- Secrets strategy and key rotation foundations
- Auditable security-relevant events

### 5) Performance Budget Enforcement

- PHPBench benchmark suite covering critical hot paths (bootstrap, routing, container, middleware)
- Performance budgets defined in `tools/php/performance-budgets.json`
- Benchmarks compared against a stored baseline with relative regression thresholds; CI blocks merges when signal is stable, otherwise advisory with artifacts and PR summary

## Documentation

- [Installation Guide](docs/INSTALL.md)
- [Architecture Overview](docs/ARCHITECTURE.md)
- [Public API Reference](docs/PUBLIC_API.md)
- [Extension Development Guide](docs/EXTENSIONS.md)
- [CLI Reference](docs/CLI_REFERENCE.md)
- [Repository Structure](docs/REPOSITORY_STRUCTURE.md)
- [PHP Feature Matrix](docs/PHP_FEATURE_MATRIX.md)

## Non-goals for v1.0

Core v1.0 does not ship a full CMS/admin product; optional first-party extensions may provide an admin shell/UI scaffolding.

## Quick start (dev)

```bash
git clone https://github.com/LennyObez/pulsar-framework.git
cd pulsar-framework
composer install
pnpm install
```

Run the test suite:

```bash
composer test          # PHPUnit (all suites)
composer phpstan       # Static analysis (level max)
composer psalm         # Static analysis (level 1)
pnpm test             # JS/TS tests (Vitest)
```

Run the full quality gate:

```bash
composer qa            # cs:fix + phpstan + psalm + test
```

See the [Installation Guide](docs/INSTALL.md) for environment details and the [CLI Reference](docs/CLI_REFERENCE.md) for available commands.

## Stability and compatibility

Pulsar uses `#[Api]` and `#[Internal]` attributes to mark its public surface:

| Surface                                                 | Stability    | Policy                                                      |
| ------------------------------------------------------- | ------------ | ----------------------------------------------------------- |
| `#[Api]` classes, interfaces, methods                   | **Stable**   | SemVer-protected. No breaking changes without a major bump. |
| `#[Internal]` or unmarked symbols                       | **Unstable** | May change between RC releases without notice.              |
| Extension lifecycle (register, preBoot, boot, postBoot) | **Stable**   | Phase ordering and contracts are finalized.                 |
| Config file format (`config/*.php`)                     | **Stable**   | Existing keys are preserved; new keys may be added.         |

During the RC phase, breaking changes to `#[Api]` symbols require explicit changelog entries and migration notes. After 1.0.0, they require a major version bump.

## Requirements

- PHP 8.5+
- Composer 2.7+
- Node.js (for optional docs/UI tooling) + pnpm

Exact toolchain is pinned in-repo to avoid "foundation rewrites".

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md).

## Code of Conduct

See [CODE_OF_CONDUCT.md](.github/CODE_OF_CONDUCT.md).

## Security

See [SECURITY.md](.github/SECURITY.md).

## License

Apache 2.0 - see [`LICENSE`](LICENSE) and [`NOTICE`](NOTICE).
