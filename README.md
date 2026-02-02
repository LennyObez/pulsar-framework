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

> **Status:** pre-alpha (0.x). APIs and architecture may change rapidly until 1.0.  
> Track milestones in [`ROADMAP.md`](ROADMAP.md) and requirements in [`PRD.md`](PRD.md).

## Why Pulsar

Pulsar targets teams building **mission-critical applications** where you need:

- **Deterministic architecture** (explicit boundaries, predictable boot pipeline)
- **Extension-first design** (stable hooks, compatibility checks, versioned lifecycle)
- **Measurable performance** (bench harness + budgets enforced in CI)
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

- **Small, fast core**: keep the hot path tight (bootstrap → route → handler → response)
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

### 3) In-house Observability Suite
No dependency on Prometheus/Grafana/Sentry.
- Structured logs + audit logging subsystem
- Metrics collector + exporters (Prometheus exposition format allowed as output)
- Tracing: spans, context propagation, sampling rules
- Error reporting: grouping, fingerprints, local viewer UI

### 4) Security Baseline (default-on)
- Session hardening, CSRF protection, security headers, rate limiting
- Secrets strategy and key rotation foundations
- Auditable security-relevant events

## Non-goals for v1.0

Pulsar v1.0 will **not** ship as a complete product CMS/admin panel/UI framework.  
However, v1.0 explicitly aims to make building an admin/CMS **straightforward via first-party extensions** (admin shell, UI foundation, and scaffolding).

## Requirements

- PHP 8.5+
- Composer 2.7+
- Node.js (for optional docs/UI tooling) + pnpm

Exact toolchain is pinned in-repo to avoid “foundation rewrites”.

## Contributing
See [CONTRIBUTING.md](.github/CONTRIBUTING.md).

## Code of Conduct
See [CODE_OF_CONDUCT.md](.github/CODE_OF_CONDUCT.md).

## Security
See [SECURITY.md](.github/SECURITY.md).

## License

Apache 2.0 — see [`LICENSE`](LICENSE) and [`NOTICE`](NOTICE).
