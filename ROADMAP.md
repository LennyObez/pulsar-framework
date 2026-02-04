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

- Middleware finalized: named groups, aliases, MiddlewareRegistry ✓
- Request convenience methods: `all()`, `input()`, `json()`, `wantsJson()` ✓
- Request validation system (typed): Validator, 11 built-in rules, ValidationMiddleware ✓
- JSON content-negotiation in ExceptionHandler ✓
- Response::validationError() factory (422 JSON) ✓
- Route constraints: per-parameter regex patterns enforced during matching ✓
- Host-based routing: exact or parameterized host matching on routes and groups ✓
- Rate-limit MVP: fixed-window in-memory RateLimiter + RateLimitMiddleware (429 + Retry-After) ✓
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

### 0.6.0 — Security Baseline v1 (CURRENT)

Deliverables:

- Session hardening + CSRF + secure headers defaults ✓
- Crypto/key management primitives (libsodium: HMAC, KDF, secretbox) ✓
- Audit logging subsystem (HMAC-chained tamper-evident entries) ✓
- SecurityConfig DTO + SessionConfig + CsrfConfig + SecurityHeadersConfig ✓
- SecurityException with factory methods for all security errors ✓
- Kernel integration: createSecurityServices() in boot pipeline ✓
  Exit criteria:
- Security checklist documented + tested

### 0.7.0 — Data Layer v1 (CURRENT)

Deliverables:

- PDO-based ConnectionInterface with lazy initialization + typed bindings ✓
- ConnectionManager for multi-connection management ✓
- Driver enum (MySQL, PostgreSQL, SQLite) with DSN builder ✓
- Typed Row/Result value objects with getInt(), getString(), getBool(), etc. ✓
- Transaction API with savepoint-based nesting ✓
- Statement wrapper for reusable prepared statements ✓
- DatabaseException with static factory methods ✓
- DatabaseConfig + ConnectionConfig DTOs with env overrides ✓
- MigrationRunner with batch-based tracking, runPending, rollback, reset ✓
- MigrationRepository for file discovery (timestamp-versioned anonymous classes) ✓
- Console commands: migrate:run, migrate:rollback, migrate:status, migrate:create ✓
- Kernel integration: optional database services in boot pipeline ✓
- CI: MySQL 8.0 + PostgreSQL 16 services for integration tests ✓
  Exit criteria:
- Integration tests with real DB in CI

### 0.8.0 — AuthN/AuthZ v1

Deliverables:

- Users, sessions, password reset, email verification
- RBAC/ABAC strategy (explicit)
- 2FA baseline
  Exit criteria:
- Threat model docs for auth + tests

### 0.9.0 — Enterprise Features v1 (CURRENT)

Deliverables:

- Multi-tenancy with pluggable resolvers (header, subdomain, path) and DB isolation ✓
- Feature flags with boolean, percentage, and contextual evaluation ✓
- Job scheduler with cron parsing and tick-based execution ✓
- Self-healing: retry policies, circuit breakers, health checks, repair system ✓
- Console commands: scheduler:tick, scheduler:list, health:check, health:repair ✓
- Kernel integration for all four enterprise services ✓
  Exit criteria:
- All enterprise primitives tested and documented

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
