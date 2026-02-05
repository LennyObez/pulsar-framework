# Pulsar — Product Requirements Document

## 1. Summary

Pulsar is a PHP 8.5 HMVC framework engineered for mission-critical and regulated environments. It targets teams who need:

- deterministic architecture and modularity,
- measurable performance and reliability,
- security/compliance by design,
- exceptional developer experience and maintainability.

**Pulsar Studio** is the umbrella name for first-party companion products built on the framework: Studio Console (observability/debugging), Studio Admin (optional CRUD/policy scaffolding), and any future first-party tooling extensions.

## 2. Problem Statement

Existing PHP frameworks tend to optimize for either:

- speed-to-market with heavy conventions and hidden magic, or
- enterprise flexibility with significant complexity and onboarding cost, or
- minimalism that pushes architecture and tooling choices to each project.

Pulsar aims to deliver a lean hot path with minimal overhead on the request path, a stable extension API, strong defaults, and measurable quality gates.

## 3. Product Goals (Measurable)

### 3.1 Performance

- Provide a benchmark harness in-repo.
- Define performance budgets for:
  - bootstrap time
  - routing dispatch time
  - container resolution time
  - memory footprint under typical loads
- Benchmarks are measured in CI and compared against a stored baseline using relative regression thresholds. The benchmark job is non-flaky by design (warmup iterations, multiple runs, median-based comparison). CI blocks merges only when the benchmark signal is stable and a regression exceeds the threshold; otherwise the job is advisory and always publishes artifacts and a PR summary.

### 3.2 DX (Developer Experience)

- Install and scaffold a running app in under 60 seconds.
- Strong IDE support: type-safe config, predictable APIs.
- One-command quality gate: tests + static analysis + format + security checks.
- Multi-environment presets (development, staging, production) out of the box.

### 3.3 Extensibility

- First-class extensions with:
  - manifest-driven discovery,
  - deterministic boot pipeline,
  - stable hook points and documented lifecycle,
  - compatibility validation and version constraints.

### 3.4 Security & Compliance

- Secure defaults: sessions, cookies, CSRF, headers, encryption.
- Audit logging and traceability.
- Evidence to support SOC2/ISO27001-style workflows: structured audit trails, tamper-evident evidence export, retention policies, and sensitive-data redaction.
- Documentation mapping features to compliance controls (GDPR, PCI-DSS, ISO27001, OWASP, NIS2, PSD2, MDR, HL7/FHIR, ISO13485).

## 4. Target Users & Personas

- Platform / Staff Engineers: need stability, modularity, performance, upgrade safety.
- Security / Compliance Engineers: need auditability, encryption, least privilege, documentation.
- Product Engineers: need fast scaffolding, strong tooling, and predictable patterns.

## 5. Scope (v1.0)

### 5.1 Core Runtime

- Kernel lifecycle (boot, request handling, termination).
- Deterministic bootstrap with lean hot path and minimal overhead on the request path.
- Framework caches: config, routes, events, views, and container bindings (where applicable).
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

### 5.7 Observability — Studio Console

No dependency on external monitoring vendors. Studio Console is the first-party observability, debugging, and operational intelligence product under the Pulsar Studio umbrella.

Studio Console is enabled by default in local/development environments. In staging and production, it is disabled by default and requires explicit opt-in with authentication or allowlisting before activation.

Deliver:

- **Structured logging** (JSON) + audit logging subsystem.
- **Metrics:**
  - in-process collector
  - OpenMetrics text exposition format (optional, standards-based output)
- **Tracing:**
  - spans, context propagation, sampling rules
  - correlation IDs linking requests, logs, errors, and traces into unified timelines
- **Error reporting:**
  - grouping, fingerprints, sensitive-data redaction
- **Console-grade capabilities:**
  - event model: structured events with typed payloads and severity levels
  - correlation IDs across the full request lifecycle (logs, traces, errors, audit entries)
  - timeline views: reconstruct request flow from ingress to response
  - search and filters: query events by time range, severity, correlation ID, tenant, user, or free text
  - retention policies: configurable per-environment retention with automatic pruning
  - sensitive-data redaction: field-level scrubbing applied before storage
  - environment gating: per-environment verbosity and feature toggles (e.g., full trace capture in staging, sampled in production)
  - evidence export and verification: export audit/event archives with a tamper-evident hash chain for integrity verification
- **Local dev report UI** (static HTML/TS app).

### 5.8 Security Baseline

- Password hashing policy, secret storage strategy.
- Session hardening (cookie flags, rotation, fixation defense).
- CSRF protection.
- Rate limiting and abuse controls.
- Security headers (CSP, HSTS, etc.) with safe defaults.

### 5.9 Data Layer (v1.0)

The v1.0 data layer is split into two tiers:

**DB foundation (v1.0 scope):**

- Database abstraction: connection management, driver support, explicit query execution.
- Migrations and rollback with versioned history.
- Transaction management and consistency patterns.

**ORM intent (v1.0 scope — foundation only):**

- A first-party ORM baseline that prioritizes explicitness: no "SQL invisibilized" magic.
- Entity mapping, hydration, and persistence through explicit method calls.
- Query building with visible, inspectable SQL output.
- Full ORM feature set (relations, eager/lazy loading, identity map) is a post-v1.0 concern; v1.0 delivers the foundational layer and public API surface.
- Only the explicitly `#[Api]`-marked surface of the ORM foundation is SemVer-stable; internal implementation details may change without notice.

Policy/metadata readiness (entity annotations, field-level access rules, audit-aware fields) is a prerequisite for Studio Admin CRUD scaffolding and is designed into the ORM foundation from the start.

### 5.10 Multi-tenancy (v1.0 baseline)

- Tenant resolution strategy.
- Resource isolation and per-tenant configuration.
- Audit trails tenant-aware.

### 5.11 Feature Flags

- Flag evaluation rules.
- Percentage rollouts and targeting.
- Auditability.

### 5.12 Operations & Integrity

- **Install DX:** target under 60 seconds from `composer create-project` to a running app, with multi-environment presets (development, staging, production).
- **Framework caches:** config, routes, events, views, and container bindings. Cache warming and invalidation via CLI commands.
- **Deploy check (`deploy:check`):** edge/perimeter validation that verifies environment configuration, required services, file permissions, and security settings before a deployment is considered ready.
- **Integrity and tamper detection:** hash manifest of framework and application files. `integrity:verify` CLI command compares current state against the manifest and reports discrepancies.
- **Self-healing guardrails:** explicit, auditable supervisor policies that detect and respond to known failure modes (stale caches, missing config, unreachable services). All corrective actions are logged and reported — no silent magic.

## 6. Non-goals (v1.0)

- No full CMS product in core v1.0. A first-party "Studio Admin" extension (CRUD scaffolding, policy-driven UI) is planned once the ORM foundation and policy/metadata layer are stable.
- No ORM that hides SQL entirely. The data layer favors explicitness: queries are visible, inspectable, and auditable.
- No "async by magic." Fibers and async patterns may exist only in controlled, opt-in components (e.g., scheduler runtime, worker processes) with strict isolation rules to prevent shared-state bleed. All Fiber usage requires explicit opt-in, documented boundaries, and benchmarked performance characteristics. Application code in the standard request path remains synchronous and deterministic.

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
  - measured against a stored baseline with relative regression thresholds
  - CI blocks merges only when the benchmark signal is stable; otherwise advisory with artifacts and PR summary always published

## 8. Release & Compatibility

- Semantic Versioning.
- Deprecation policy with timelines and upgrade guides.
- Release candidates before 1.0.0.

## 9. Acceptance Criteria (v1.0)

- Install and scaffold a running app in under 60 seconds.
- Modules + extensions can register routes, DI bindings, and commands.
- Studio Console produces metrics, logs, traces, and a viewable report with correlation-based timelines.
- Security baseline enabled by default.
- CI enforces quality gates; benchmarks block merges when signal is stable, otherwise advisory with artifacts.
- `deploy:check` validates environment readiness.
- `integrity:verify` detects file-level tampering against a hash manifest.
