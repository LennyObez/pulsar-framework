# Pulsar — Product Requirements Document

## 1. Summary

Pulsar is a PHP 8.5 HMVC framework engineered for mission-critical and regulated environments. It targets teams who need:

- deterministic architecture and modularity,
- measurable performance and reliability,
- security/compliance by design,
- exceptional developer experience and maintainability.

## 2. Problem Statement

Existing PHP frameworks tend to optimize for either:

- speed-to-market with heavy conventions and hidden magic, or
- enterprise flexibility with significant complexity and onboarding cost, or
- minimalism that pushes architecture and tooling choices to each project.

Pulsar aims to deliver a small, fast core with a stable extension API, strong defaults, and measurable quality gates.

## 3. Product Goals (Measurable)

### 3.1 Performance

- Provide a benchmark harness in-repo.
- Define performance budgets for:
  - bootstrap time
  - routing dispatch time
  - container resolution time
  - memory footprint under typical loads
- Prevent regressions via CI (baseline + thresholds).

### 3.2 DX (Developer Experience)

- “Time to First App” under 5 minutes with CLI init + scaffold.
- Strong IDE support: type-safe config, predictable APIs.
- One-command quality gate: tests + static analysis + format + security checks.

### 3.3 Extensibility

- First-class extensions with:
  - manifest-driven discovery,
  - deterministic boot pipeline,
  - stable hook points and documented lifecycle,
  - compatibility validation and version constraints.

### 3.4 Security & Compliance

- Secure defaults: sessions, cookies, CSRF, headers, encryption.
- Audit logging and traceability.
- Documentation mapping features to compliance controls (GDPR, PCI-DSS, ISO27001, OWASP, NIS2, PSD2, MDR, HL7/FHIR, ISO13485).

## 4. Target Users & Personas

- Platform / Staff Engineers: need stability, modularity, performance, upgrade safety.
- Security / Compliance Engineers: need auditability, encryption, least privilege, documentation.
- Product Engineers: need fast scaffolding, strong tooling, and predictable patterns.

## 5. Scope (v1.0)

### 5.1 Core Runtime

- Kernel lifecycle (boot, request handling, termination).
- Deterministic bootstrap and caching strategy.
- Error handling (developer vs production modes).

### 5.2 HTTP & Routing

- PSR-aligned request/response handling.
- Fast routing, named routes, groups, middleware pipeline.
- Input validation layer (typed, explicit).

### 5.3 Container & DI

- PSR-11 compatibility as baseline.
- Compile-ready container design (reduce runtime reflection in hot paths).
- Clear lifetimes/scopes (singleton, request, transient).

### 5.4 HMVC Modules

- Canonical module structure.
- Explicit boundaries and contracts.
- Module discovery and isolation.

### 5.5 Extension System

- `pulsar.json` manifest.
- Extension lifecycle: discover → validate → register → boot.
- Stable hook points: DI bindings, routes, console commands, migrations, assets.
- Version compatibility checks and deprecation strategy.

### 5.6 CLI & DX Tooling

- `bin/pulsar`:
  - init project, create module/extension, generate keys
  - show: routes, container graph, diagnostics
  - run: tests, qa, benchmarks
- Scaffolding templates maintained and versioned.

### 5.7 Observability Suite (In-house)

No dependency on Prometheus/Grafana/Sentry.
Deliver:

- Structured logging (JSON) + audit logging subsystem.
- Metrics:
  - in-process collector
  - exporters (Prometheus exposition format allowed as output)
- Tracing:
  - spans, context propagation, sampling rules
- Error reporting:
  - grouping, fingerprints, local viewer
- Local dev report UI (static HTML/TS app is acceptable).

### 5.8 Security Baseline

- Password hashing policy, secret storage strategy.
- Session hardening (cookie flags, rotation, fixation defense).
- CSRF protection.
- Rate limiting and abuse controls.
- Security headers (CSP, HSTS, etc.) with safe defaults.

### 5.9 Data Layer (v1.0 baseline)

- Database abstraction strategy (must be explicit and testable).
- Migrations and rollback.
- Transaction management and consistency patterns.

### 5.10 Multi-tenancy (v1.0 baseline)

- Tenant resolution strategy.
- Resource isolation and per-tenant configuration.
- Audit trails tenant-aware.

### 5.11 Feature Flags

- Flag evaluation rules.
- Percentage rollouts and targeting.
- Auditability.

## 6. Non-goals (v1.0)

- No full CMS product in v1.0, but official UI foundation + admin shell as optional first-party extensions.
- Opinionated ORM that hides SQL entirely (keep explicitness).
- “Async by magic” without a real runtime/scheduler.

## 7. Quality Gates (Mandatory)

- Tests:
  - unit + integration + e2e where relevant
  - critical-path coverage targets
- Static analysis:
  - PHPStan + Psalm
  - Qodana inspections
- Formatting:
  - PHP-CS-Fixer
  - ESLint + Prettier
- Security:
  - dependency scanning
  - secret scanning
- Docs:
  - architecture + extension lifecycle + security model
- Benchmarks:
  - baseline stored and regressions blocked.

## 8. Release & Compatibility

- Semantic Versioning.
- Deprecation policy with timelines and upgrade guides.
- Release candidates before 1.0.0.

## 9. Acceptance Criteria (v1.0)

- “Hello World” app via CLI scaffolding.
- Modules + extensions can register routes, DI bindings, and commands.
- Observability suite produces metrics/logs/traces and a viewable report.
- Security baseline enabled by default.
- CI enforces quality gates and performance budgets.
