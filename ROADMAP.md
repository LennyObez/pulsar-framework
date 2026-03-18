# Pulsar roadmap

## Principles

- Lock foundations early (tooling + repo layout + CI) to avoid rework.
- Core stays small; everything else is an extension.
- Each milestone ships with: docs + tests + benchmarks + upgrade notes (when needed).

## Version plan

### 0.0.1 - Tooling and repo foundation

- Repository structure, docs skeleton
- CI workflows (PHP + TS) + quality gate scripts
- Toolchain: PHPUnit, PHPStan, Psalm, PHP-CS-Fixer, Rector, Qodana, TypeScript, ESLint, Prettier, Vitest
- `scripts/qa.sh` as single entrypoint

### 0.1.0 - Core runtime MVP

- Kernel lifecycle
- PSR-11 compatible container (MVP)
- Router MVP (static routes, groups, middleware pipeline)
- Minimal HTTP abstraction
- Example app in `examples/hello-world`

### 0.2.0 - DX + extension system MVP

- `bin/pulsar` CLI: init, scaffold module/extension, show routes, diagnostics
- Extension manifest `pulsar.json` + discovery + lifecycle
- Deterministic boot pipeline (register, boot)

### 0.3.0 - Config, caching, errors

- Typed config system + env loading
- Cache layer and bootstrap cache priming
- Error handling modes: dev vs prod, safe error pages
- Structured logging baseline

### 0.4.0 - HTTP and routing advanced

- Middleware finalized: named groups, aliases, MiddlewareRegistry
- Request validation system (typed): Validator, 11 built-in rules, ValidationMiddleware
- Route constraints, host-based routing
- Rate-limit MVP: fixed-window in-memory RateLimiter + RateLimitMiddleware

### 0.5.0 - Observability suite v1

- Metrics collector + OpenMetrics exporter
- Tracing core (spans + context)
- Error grouping/reporting
- Diagnostics route

### 0.6.0 - Security baseline v1

- Session hardening + CSRF + secure headers defaults
- Crypto/key management primitives (libsodium)
- Audit logging subsystem (HMAC-chained tamper-evident entries)

### 0.7.0 - Data layer v1

- PDO-based database abstraction with typed bindings
- ConnectionManager for multi-connection management
- Transaction API with savepoint-based nesting
- Migration system with batch-based tracking
- CI: MySQL 8.0 + PostgreSQL 16 services for integration tests

### 0.8.0 - AuthN/AuthZ v1

- Authentication with Argon2id, session guards, remember-me tokens
- RBAC/ABAC authorization
- 2FA baseline with TOTP and recovery codes
- Password reset and email verification flows

### 0.9.0 - Enterprise features v1

- Multi-tenancy with pluggable resolvers and database isolation
- Feature flags with boolean, percentage, and contextual evaluation
- Job scheduler with cron parsing and tick-based execution
- Self-healing: retry policies, circuit breakers, health checks, repair system

### 1.0.0-rc.1 - Stabilization

- `#[Api]` and `#[Internal]` attributes for public API boundary enforcement
- PHPBench performance benchmark suite with budget assertions
- 4 E2E test suites
- 6 documentation files (install, extensions, cli-reference, upgrade, public-api, performance-budgets)
- Coverage threshold raised to 70%

### rc.12 → 1.0.0 - GA gating work

external audit (2026-05-11) and the in-flight Pulsar audit cycle leave the
following work between rc.11 and the 1.0.0 GA tag. Each item links to its
audit finding ID in `.claude/findings.md` and the PRD entry in
`docs/PRD-1.0.0.md`.

**Blockers (GA tag cannot be cut while open)** — per **ADR-0032** (2026-05-12,
supersedes ADR-0025 + ADR-0030):

- **VECTORS-WA-01** — W3C WebAuthn conformance vector suite imported and
  running green in CI. Source: <https://github.com/web-auth/webauthn-test-
  vectors> + W3C Level 2/3 corpus. Coverage: 7 attestation formats +
  authentication ceremony + counter monotonicity.
- **VECTORS-OAUTH-01** — OAuth2 / OIDC conformance vector suite imported
  and green. Source: OpenID Foundation Self-Certification + RFC 6749/7636/
  7662/8176 examples + OAuth-in-the-Wild attack corpus.
- **VECTORS-JOSE-01** — JOSE / JWT conformance vector suite imported and
  green. Source: RFC 7515-7519 examples + the JWT attack corpus (alg:none,
  RS256→HS256 confusion, kid traversal, critical header bypass).
- **F385.M4** — moved to **1.1.0 blocker** per ADR-0032. The 1.0.0 GA tag
  may be cut with the conformance vector suite in place; the external
  security audit memo lands before 1.1.0.

**High-priority (close to ship)**

- **SEC-EXT-01** — extension autoload restructure (composer.json mappings
  → manifest-driven registry).
- **SEC-EXT-02** — CMS plugin signature enforcement in production.
- **TOOL-DEP-01/02** — deploy check refuses production startup when
  artifact signature is missing or invalid.
- **QUAL-STA-01 / QUAL-PSA-01** — PHPStan baseline + Psalm suppression
  ratchet to zero (or ≤ N documented entries).

**Quality / coverage ramp**

- **QUAL-COV-01** — CI coverage gate ramp: rc.12 80 (done), rc.13 per-
  module 95 for auth/security/crypto/audit, 1.0.0 GA 90 global.
- **QUAL-COV-02** — Infection MSI ramp: rc.12 80 (done),
  rc.13 90 for src/Auth, src/Security, src/Audit.
- **TOOL-GATE-01** — composer qa already enriched with `@security:lint`
  (semgrep) in rc.12; `qa:full` adds `@mutation` + `@test:coverage`.

**Documentation and process**

- Final security audit pass evidence published under `docs/audit/`.
- Release notes + upgrade guide for `rc.11 → 1.0.0`.
- Long-term maintenance and supported-versions policy.
- Performance baseline documentation for production deployments.
- All ADRs reviewed for code-vs-doc drift (ARCH-DRIFT-01 fixed).

PRD reference: `docs/PRD-1.0.0.md`.

### Previous releases

- rc.2: OpenMetrics rename, public API snapshot system
- rc.3: Pulsar Studio observability subsystem
- rc.4: Static analysis cleanup (319 Qodana issues resolved)
- rc.5: PHP 8.x feature matrix, JIT/preloading deploy checks, benchmark dashboard, key:generate
- rc.6: Persistent HTTP runtime
- rc.7: Payments extension (modular monolith), architecture rules enforcement
- rc.8: CLI scaffolding (7 make:\* commands), ADR governance, boundary enforcement, boot profiler
- rc.9: Boundary violations resolved, post-audit remediation (12 phases), social SSO, key rotation, compliance matrix
- rc.10: MCP server, ORM, Admin extension
- rc.11: DI container, application cache (PSR-6/PSR-16), i18n, OpenTelemetry, zero-trust architecture, OAuth2/WebAuthn, queue system, mail/notifications, form extension, API tooling, CMS extension, PHPUnit 13
- rc.12: Auth fork consolidation, OAuth2 JWKS RS256, 2FA fail-closed, BLAKE2b token indices, PSR-7 CRLF guard, body cap, audit fail-closed deploy check, subprocess env allowlist, Semgrep export rule, ASVS L2 matrix stub, eIDAS production deploy gate, GDPR analytics defaults, Infection MSI ramp, coverage threshold ramp

## Commit message convention

Use Conventional Commits:

- feat(scope): new feature
- fix(scope): bug fix
- perf(scope): performance improvement
- refactor(scope): code restructuring
- docs(scope): documentation
- test(scope): test additions/changes
- chore(scope): maintenance
- security(scope): security-related changes

Scope examples: core, router, container, http, console, dx, ext, security, observability, ci, tooling, docs.
