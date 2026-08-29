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
- **Security by default** (secure sessions, CSRF, security headers, auditability). Encryption at rest, session
  encryption and audit HMAC integrity are derived from `PULSAR_MASTER_KEY` and are inactive until you set it —
  `composer deploy:check` refuses staging and production without one
- **Regulated-domain readiness** (control mappings whose outcome is computed against your deployment by
  `pulsar compliance:report`, not asserted here)

## Compliance-ready controls

Pulsar provides framework-level controls for seven regulatory and standards frameworks. It does **not** claim certification, and this table is not a statement about your deployment. It lists what the framework offers and what remains yours.

For what a deployment actually achieves, run `pulsar compliance:report`: it assesses each control with a probe against the running application and exits non-zero on a control an enabled framework claims and the deployment does not show. On the default framework set, a first run is red — see [docs/compliance.md](docs/compliance.md) for the baseline and the reasons.

| Framework          | What Pulsar provides                                                                                                            | What the integrator must add                                                                                                                                                                                                 |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **SOC 2**          | Audit logging with HMAC chain (requires `PULSAR_MASTER_KEY`), RBAC, session management, observability, incident interfaces      | Organizational policies, personnel training, SOC 2 Type II audit engagement. 24 of the 42 criteria are organizational and are reported as operator responsibilities, not as coverage                                         |
| **HIPAA 2026**     | Encryption at rest (requires `PULSAR_MASTER_KEY`), MFA support, audit trails, access controls, incident reporting interfaces    | TLS termination, BAA execution, PHI data handling procedures, workforce training, and 72-hour restoration — which needs a backup primitive Pulsar does not ship                                                              |
| **ISO 27001:2022** | Annex A technological controls (A.8.x): authentication, logging, cryptography, access restriction, configuration management     | ISMS documentation, risk treatment plans, management review, internal audit program                                                                                                                                          |
| **GDPR**           | Consent management interfaces, data retention/purging interfaces, encryption, audit trails, `#[SensitiveParameter]` masking     | DPO appointment, DPIA execution, data processing agreements, breach notification procedures                                                                                                                                  |
| **PCI DSS v4.0.1** | Tokenization, encryption, key management, session hardening, audit logging with retention, CSRF protection                      | QSA engagement, network segmentation, vulnerability scanning, PCI DSS SAQ/ROC                                                                                                                                                |
| **ISO 42001:2023** | AI governance **contracts** plus in-memory reference stores, via the optional `pulsar/ai-governance` extension (off by default) | Durable stores for every `InMemory*` binding, a `MonitoringHookInterface` implementation (none exists in the tree, so Clause 9.1 cannot be satisfied today), AI policy documentation, model card content, AIMS certification |
| **NIS2**           | Cryptography, access controls, incident reporting, monitoring, resilience (circuit breaker, retry)                              | Risk management policies, supply chain security, incident notification to authorities                                                                                                                                        |

> **ISO 42001:2023 differentiator**: Pulsar is the first PHP framework to ship AI Management System **contracts** — model registry, impact assessments, explainability, training data governance, and lifecycle management with deployment gates, mapped to ISO 42001 clauses. What ships behind them are in-memory reference implementations suitable for development, and one contract, `MonitoringHookInterface`, has no implementation at all. Treat this as a head start on the interfaces, not as a working AI management system.

**Additional frameworks** (PSD2, eIDAS, MDR, HL7/FHIR, ISO 13485) are covered through extensions and compliance event mappings. See [docs/compliance.md](docs/compliance.md) for the full control matrix, and run `pulsar compliance:report` for the matrix of the deployment in front of you.

## Core principles

- **Lean hot path**: fast core with minimal overhead on the request path (bootstrap → route → handler → response)
- **No hidden magic**: explicit configuration, explicit dependencies, type-safe APIs
- **Compile-ready DI**: reduce runtime reflection overhead where possible
- **HMVC done strictly**: modules are first-class boundaries with clear contracts
- **DX as a feature**: CLI, scaffolding, diagnostics, and quality gates are part of the product

## Features (roadmap-driven)

### 1) Deterministic HMVC modules

- Canonical module layout and discovery
- Explicit module boundaries and contracts
- Versioned module lifecycle

### 2) Extension system

- `pulsar.json` manifest
- Discover → validate → register → boot
- Stable hooks: DI bindings, routes, console commands, migrations, assets
- Compatibility validation and deprecation strategy

### 3) Pulsar Studio

No dependency on external monitoring vendors.

- Structured logs + audit logging subsystem
- Metrics collector + exporters (OpenMetrics text exposition format)
- Tracing: spans, context propagation, sampling rules
- Error reporting: grouping, fingerprints, local viewer UI

### 4) Security baseline (default-on)

- Session hardening, CSRF protection, security headers, rate limiting
- Secrets strategy and key rotation foundations
- Auditable security-relevant events

### 5) Performance budget enforcement

- PHPBench benchmark suite covering critical hot paths (bootstrap, routing, container, middleware)
- Performance budgets defined in `tools/php/performance-budgets.json`
- Benchmarks compared against a stored baseline with relative regression thresholds; CI blocks merges when signal is stable, otherwise advisory with artifacts and PR summary

## Documentation

- [Installation Guide](docs/install.md)
- [Architecture Overview](docs/architecture.md)
- [Public API Reference](docs/public-api.md)
- [Extension Development Guide](docs/extensions.md)
- [CLI Reference](docs/cli-reference.md)
- [Repository Structure](docs/repository-structure.md)
- [PHP Feature Matrix](docs/php-feature-matrix.md)

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

See the [Installation Guide](docs/install.md) for environment details and the [CLI Reference](docs/cli-reference.md) for available commands.

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

## Code of conduct

See [CODE_OF_CONDUCT.md](.github/CODE_OF_CONDUCT.md).

## Security

See [SECURITY.md](.github/SECURITY.md).

## License

Apache 2.0 - see [`LICENSE`](LICENSE) and [`NOTICE`](NOTICE).
