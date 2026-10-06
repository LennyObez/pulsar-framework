# Pulsar Framework

<div align="center">
  <!-- Logo placeholder intentionally omitted until branding is finalized -->
  <h3>Next-generation PHP 8.5+ HMVC framework for security-critical, regulated systems</h3>
</div>

<div align="center">

[![CI](https://github.com/LennyObez/pulsar-framework/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/LennyObez/pulsar-framework/actions/workflows/ci.yml?query=branch%3Amain)
[![License](https://img.shields.io/badge/license-Apache--2.0-green.svg)](LICENSE)

</div>

> **Status:** Release Candidate (1.0.0-rc.12). The `#[Api]`-marked surface is SemVer-stable; non-`#[Api]` internals may change until 1.0.0.
> Track milestones in [`ROADMAP.md`](ROADMAP.md).

The badge is pinned to `main` and reports the last run there, which is not the
same as "current": development happens on branches, so between merges it can be
weeks old. Click it for the run list rather than reading the colour as today's
state. A `php 8.5+` shield used to sit beside it; it asserted nothing a build
could contradict — `composer.json` requires `>=8.5.1` and is the only thing worth
reading for that — so it is gone rather than decorative.

## What this repository can actually show you

Not a feature list. Four things a reader can check in the tree, in about ten
minutes:

**A compliance control cannot report itself satisfied.** `ControlDeclaration` has
no status parameter — look at
[`src/Compliance/Control/ControlDeclaration.php`](src/Compliance/Control/ControlDeclaration.php)
and there is nowhere to write "Implemented". A control's outcome is the return
value of a probe run against the booted application. The rule exists because of a
defect the project found in itself and wrote down: PCI-DSS Req 3.4 was registered
as `Implemented` while naming a class that could not be constructed and was used
nowhere ([ADR-0041](docs/adr/0041-the-token-vault-takes-a-connection.md)).

**When the audits landed, the number went down.** A run of adversarial ADRs took
controls that had been reading green and made them red, because what they rested
on was not a measurement: a resolved class name is not proof
([ADR-0063](docs/adr/0063-a-transparency-subsystem-is-exercised-not-resolved.md)),
a loaded libsodium is not proof
([ADR-0061](docs/adr/0061-a-loaded-extension-is-not-a-measurement.md)), and proof
about something other than the control's own subject is not proof
([ADR-0062](docs/adr/0062-proof-must-be-about-the-control-subject.md)). The AI
Act mapping went to satisfying nothing on any deployment. Each was then closed by
measuring the thing, not by relaxing the rule.

**What is missing is named, in the same table as what is present.** Two gaps
this paragraph used to name have closed, and neither closed by relabelling. A
backup and restore primitive now ships,
[`SealedArchiveBackupService`](src/Resilience/Backup/SealedArchiveBackupService.php),
and NIST CSF RC.RP rests on a round trip the report performs rather than on the
class resolving: the deployment's own service seals an archive, reads it back,
refuses a copy with one byte changed and returns the payload byte for byte. With
no `PULSAR_MASTER_KEY` nothing is bound and RC.RP stays red, and a green result
still does not show that anyone has restored the real data inside a recovery
window. That rehearsal is yours ([docs/backup.md](docs/backup.md)).
`MonitoringHookInterface` has its first implementation,
[`GovernanceConformityHook`](extensions/ai-governance/src/Internal/Monitoring/GovernanceConformityHook.php),
which is registered by default when `pulsar/ai-governance` is enabled. It
re-checks that a model in service still meets the obligations it was admitted
under. It does not measure accuracy, drift or bias, which stay yours. What is
still red: `DsarStoreInterface` has no implementation, so CCPA §1798.100 fails
on any deployment that enables CCPA, and PCI DSS Req 3.4 fails until you create
the `token_vault` table, because no migration ships it. GDPR Art. 15 (subject
access) is not declared in the GDPR mapping at all, so the default green GDPR
report says nothing about it. All of it is in
[docs/compliance.md](docs/compliance.md), stated as plainly as the rest.

**The default posture is one framework, not nineteen.** Nineteen have mappings;
`config/compliance.php` enables `Gdpr` alone, because a deployment is held to what
it enables ([ADR-0046](docs/adr/0046-a-claim-is-something-the-installation-delivers.md)).
Bundled product extensions are off by default for the same reason.

The gates behind the badge: PHPStan at max and Psalm at level 1 with zero errors,
PHPBench `#[Assert]` budgets as a build-failing gate, a merge-base-versus-change
benchmark comparison on one runner that refuses anything 5% slower, Deptrac plus
an `#[Api]`/`#[Internal]` boundary check, and an API snapshot that has to be
regenerated and reviewed before a public signature can change. `composer qa` runs
the set.

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
- **Measurable performance** (PHPBench budgets asserted per subject as a hard CI gate, plus a pull-request comparison that measures the merge base and the change on the same runner and refuses anything more than 5% slower)
- **Security by default** (secure sessions, CSRF, security headers, auditability). Encryption at rest, session
  encryption and audit HMAC integrity are derived from `PULSAR_MASTER_KEY` and are inactive until you set it —
  `composer deploy:check` refuses staging and production without one
- **Regulated-domain readiness** (control mappings whose outcome is computed against your deployment by
  `pulsar compliance:report`, not asserted here)

## Compliance-ready controls

Pulsar provides framework-level controls for seven regulatory and standards frameworks. It does **not** claim certification, and this table is not a statement about your deployment. It lists what the framework offers and what remains yours.

For what a deployment actually achieves, run `pulsar compliance:report`: it assesses each control with a probe against the running application and exits non-zero on a control an enabled framework claims and the deployment does not show. On the default framework set, a first run is red — see [docs/compliance.md](docs/compliance.md) for the baseline and the reasons.

| Framework          | What Pulsar provides                                                                                                                                                                         | What the integrator must add                                                                                                                                                                                                                                                                                    |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **SOC 2**          | Audit logging with HMAC chain (requires `PULSAR_MASTER_KEY`), RBAC, session management, observability, incident interfaces                                                                   | Organizational policies, personnel training, SOC 2 Type II audit engagement. 24 of the 42 criteria are organizational and are reported as operator responsibilities, not as coverage                                                                                                                            |
| **HIPAA 2026**     | Encryption at rest (requires `PULSAR_MASTER_KEY`), MFA support, audit trails, access controls, incident reporting interfaces                                                                 | TLS termination, BAA execution, PHI data handling procedures, workforce training, and a rehearsed restore of real data inside the 72-hour window. Pulsar ships the sealed backup primitive (`pulsar backup:run`, `backup:verify`, `backup:restore`); the schedule, the offsite copy and the rehearsal are yours |
| **ISO 27001:2022** | Annex A technological controls (A.8.x): authentication, logging, cryptography, access restriction, configuration management                                                                  | ISMS documentation, risk treatment plans, management review, internal audit program                                                                                                                                                                                                                             |
| **GDPR**           | Consent management interfaces, data retention/purging interfaces, encryption, audit trails, `#[SensitiveParameter]` masking                                                                  | DPO appointment, DPIA execution, data processing agreements, breach notification procedures                                                                                                                                                                                                                     |
| **PCI DSS v4.0.1** | Tokenization, encryption, key management, session hardening, audit logging with retention, CSRF protection                                                                                   | QSA engagement, network segmentation, vulnerability scanning, PCI DSS SAQ/ROC                                                                                                                                                                                                                                   |
| **ISO 42001:2023** | AI governance contracts, database-backed stores by default, and one shipped monitoring hook (`GovernanceConformityHook`), via the optional `pulsar/ai-governance` extension (off by default) | A database connection for the stores (a `database` store with no connection fails rather than falling back to memory), monitoring hooks for accuracy, drift and bias (the shipped hook checks governance conformity only), AI policy documentation, model card content, AIMS certification                      |
| **NIS2**           | Cryptography, access controls, incident reporting, monitoring, resilience (circuit breaker, retry)                                                                                           | Risk management policies, supply chain security, incident notification to authorities                                                                                                                                                                                                                           |

> **ISO 42001:2023 differentiator**: Pulsar ships AI Management System **contracts** — model registry, impact assessments, explainability, training data governance, and lifecycle management with deployment gates, mapped to ISO 42001 clauses. What ships behind them are database-backed stores by default (in-memory ones remain for development) and one monitoring hook, `GovernanceConformityHook`, which re-checks that a model in service still meets the obligations it was admitted under and does not measure accuracy, drift or bias. Treat this as governance record-keeping and deployment gating, not as a complete AI management system.

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
- The budgets that fail a build are the `#[Assert]` attributes on the subjects in `tests/Benchmark`; PHPBench exits non-zero on a breach and the CI step propagates it. `tools/php/performance-budgets.json` and the two `budgets.*.json` overrides beside it are read by no code in this repository — they are a reference table, not the enforcement, and this line used to imply otherwise
- `benchmark-regression.yml` benchmarks the merge base and the pull request on the same runner in the same job, and fails the build on a subject more than 5% slower. It records its own baseline: a committed one holds ops-per-second figures from whichever machine wrote them, which on a shared runner is noise rather than signal
- Nothing is compared against a checked-in baseline file. There used to be one, `tools/php/benchmark-baseline.json`, and it held a single `_comment` key for the whole release-candidate phase — so every benchmark reported NEW and the check went green having compared nothing. The runner now refuses a run in which no benchmark matched its baseline

## Documentation

- [Documentation index](docs/README.md) - every page under docs/, grouped, with a one-line description each.
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
