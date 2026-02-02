# Pulsar Roadmap (SemVer)

## Principles
- Lock foundations early (tooling + repo layout + CI) to avoid rework.
- Core stays small; everything else is an extension.
- Each milestone ships with: docs + tests + benchmarks + upgrade notes (when needed).

## Version Plan

### 0.0.1 — Tooling & Repo Foundation (LOCK-IN)
Deliverables:
- Repository structure, docs skeleton
- CI workflows (PHP + TS) + quality gate scripts
- Toolchain configured:
  - PHP: PHPUnit, PHPStan, Psalm, PHP-CS-Fixer, Rector, Qodana
  - TS: TypeScript, ESLint (flat config), Prettier, Vitest
- `scripts/qa.sh` as single entrypoint
  Exit criteria:
- CI green
- `./scripts/qa.sh` runs everything locally

### 0.1.0 — Core Runtime MVP (Hello World)
Deliverables:
- Kernel lifecycle
- PSR-11 compatible container (MVP)
- Router MVP (static routes, groups, middleware pipeline MVP)
- Minimal HTTP abstraction or PSR-7 adoption decision
- Example app in `examples/hello-world`
  Exit criteria:
- Example app runs
- Unit tests + benchmark harness placeholder (real baselines start 0.2)

### 0.2.0 — DX + Extension System MVP (EARLY)
Deliverables:
- `bin/pulsar` CLI:
  - init, scaffold module/extension, show routes, diagnostics
- Extension manifest `pulsar.json` + discovery + lifecycle
- Deterministic boot pipeline (register → boot)
  Exit criteria:
- A first-party extension can register DI bindings + routes + commands

### 0.3.0 — Config, Caching, Errors (Production Shape)
Deliverables:
- Typed config system + env loading
- Cache layer and bootstrap cache priming
- Error handling modes: dev vs prod, safe error pages
- Logging baseline (structured)
  Exit criteria:
- No “magic” config; everything type-safe and discoverable

### 0.4.0 — HTTP & Routing Advanced
Deliverables:
- Middlewares finalized
- Route naming, constraints, host-based routing
- Request validation system (typed)
- Rate-limit MVP (foundation)
  Exit criteria:
- Routing and validation benchmarks with stored baselines

### 0.5.0 — Observability Suite v1 (In-house)
Deliverables:
- Metrics collector + exporters (Prometheus format allowed as output)
- Tracing core (spans + context)
- Error grouping/reporting
- Local report viewer UI
  Exit criteria:
- Example app produces logs/metrics/traces and viewable report

### 0.6.0 — Security Baseline v1
Deliverables:
- Session hardening + CSRF + secure headers defaults
- Crypto/key management primitives
- Audit logging subsystem (domain events + immutable logs)
  Exit criteria:
- Security checklist documented + tested

### 0.7.0 — Data Layer v1
Deliverables:
- Migrations + rollback
- Transaction API
- DB access strategy that remains explicit/testable
  Exit criteria:
- Integration tests with real DB in CI

### 0.8.0 — AuthN/AuthZ v1
Deliverables:
- Users, sessions, password reset, email verification
- RBAC/ABAC strategy (explicit)
- 2FA baseline
  Exit criteria:
- Threat model docs for auth + tests

### 0.9.0 — Enterprise Features v1
Deliverables:
- Multi-tenancy
- Feature flags
- Job scheduler + queue baseline
- Extension compatibility validation + deprecation policy tooling
  Exit criteria:
- Upgrade guide templates + compatibility checks

### 1.0.0-rc.1 — Stabilization
Deliverables:
- API freeze for public extension API
- Performance budgets enforced
- Documentation complete
- Migration guides where applicable

### 1.0.0 — First Stable Release
Deliverables:
- All acceptance criteria in PRD satisfied
- Release notes + security policy + long-term maintenance plan

## Commit Message Convention
Use Conventional Commits:
- feat(scope): ...
- fix(scope): ...
- perf(scope): ...
- refactor(scope): ...
- docs(scope): ...
- test(scope): ...
- chore(scope): ...

Scope examples: core, router, container, http, console, dx, ext, security, observability, ci, tooling, docs.

## Required Commit Output (for Claude)
For each commit step Claude proposes:
- Commit summary (1 line)
- Commit description (bullet list)
- SemVer bump suggestion (patch/minor/major)
