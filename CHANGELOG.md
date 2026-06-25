# Changelog

All notable changes to Pulsar Framework are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Native localized route slugs: translate URL path segments per locale (`/fr/developpement`, `/nl/ontwikkeling`) for a single route registered under a canonical key. Includes `SlugRegistry`, `LocalizedSlugMiddleware` (constant-time rewrite + configurable canonical 301 for non-canonical aliases, route parameters preserved), `LocalizedUrlGenerator` with the `route()` helper and `@route` directive, slug-aware hreflang/`x-default` output, and the `i18n:slugs:lint` console command (completeness + collision checks, wired into CI). Compiled once at boot with zero runtime cost when unconfigured.
- Managed Challenge: a self-hosted, privacy-preserving CAPTCHA provider (`captcha_provider: 'managed'`) — an invisible proof-of-work alternative to Cloudflare Turnstile / hCaptcha with no external service, no cookies, and no fingerprinting. Signed, single-use, expiring challenges (`sodium_crypto_auth`, master sub-key) solved in a Web Worker and verified server-side (signature → freshness → proof-of-work → replay cache). Ships `ManagedChallengeService`/`Verifier`/`Renderer`, the `@shield` directive, same-origin widget + worker assets (CSP `script-src 'self'` clean), and `managed_challenge_bits`/`ttl`/`field_name` config.
- i18n `negotiate_unprefixed_locale` config flag (default `true`, backward-compatible). When `false`, the active locale for an unprefixed URL is always the default locale instead of the Accept-Language–negotiated one, so default-locale URLs stay canonical and are never redirected to a negotiated localized-slug translation. The negotiated preference is exposed via the `_negotiated_locale` request attribute for application-level courtesy redirects at `/`.

## [1.0.0-rc.11] - 2026-02-12

### Added

- Interactive REPL shell with safe mode and configurable environment.
- DI container with autowiring, contextual bindings, tagged services, lazy proxies, and compiled container support.
- Application cache layer with PSR-6/PSR-16 adapters, 6 drivers (array, file, Redis, Memcached, APCu, database), tags, atomic locks, and encrypted cache support.
- i18n subsystem with translator, message catalogs, ICU formatters, locale negotiation, and CLI tools (`lang:check`, `lang:export`, `lang:import`).
- OpenTelemetry OTLP extension for trace and metric export.
- CSP, HSTS, and cross-origin security header DTOs.
- Extension trust tiers with capability-gated proxies.
- Session management subsystem with pluggable drivers and fingerprinting.
- 82 validation rules including regulated-domain validators (IBAN, SWIFT, VAT, NPI) and policy analyzers.
- Multi-runtime support (CGI, persistent worker, CLI).
- Database enhancements: connection pooling, read/write routing, automatic failover, query result cache, and connection monitoring.
- PSR-7/PSR-15/PSR-17 adopted as the canonical HTTP layer.
- Templating engine with design system tokens and component playground.
- Zero-trust architecture with NIST 800-207 compliance primitives.
- OAuth2/OIDC and WebAuthn extensions.
- Compliance events and specialized loggers for regulated audit trails.
- Queue system with pluggable drivers (sync, database, Redis), retry policies, and dead letter handling.
- Route model binding with AuthZ enforcement.
- Mail transport abstraction and notification system (mail, database, broadcast channels).
- Form extension with typed fields, validation, and CSRF integration.
- API tooling with OpenAPI spec generation from route metadata.
- Deterministic build pipeline with artifact hashing and reproducible output.
- Expanded performance budgets with tiered enforcement (block, warn, advisory).
- CMS extension: content engine, media library, comments, search, SEO system, theme management, security hardening, user management, plugin system, commerce, live CSS editor, and import/export tools.
- Architecture decision records 0016 through 0025.
- Unit tests for 15 CLI commands and test suite OOM fixes.
- YAML issue templates and proposal forms.
- CODEOWNERS file.

### Changed

- PHPUnit upgraded from 12.5 to 13.0 with full API migration.
- PHPStan memory limit increased to 1G.
- Deprecated `finfo_close()` call removed.
- PHPUnit mocks replaced with stubs where no expectations are set.

## [1.0.0-rc.10] - 2026-02-10

### Added

- MCP server extension with project metadata introspection layer.
- ORM extension with entity mapping, query builder, and repository pattern.
- Admin extension with schema-driven UI builder.
- Unified extension frontend convention (shared asset pipeline).

### Changed

- Explicit import ordering enforced for cross-platform CS Fixer consistency.
- 226 Qodana code quality findings resolved.
- Test coverage expanded to 74%.

## [1.0.0-rc.9] - 2026-02-09

### Added

- Key rotation system with key ring and kid (key identifier) tracking.
- SQLite-backed rate limiter with trusted proxy support.
- TOTP replay protection with cross-process guard.
- PSR-7/PSR-15 bridge extension for interoperability with PSR middleware.
- Observability export extension with JSON Lines exporters.
- Data retention, consent management, and incident response interfaces.
- Compliance matrix mapping framework capabilities to regulatory controls.
- `since` annotations backfilled on all `#[Api]` attributes.

### Changed

- All 123 boundary violations resolved with `#[Api]` interfaces.
- Post-audit remediation across 12 phases covering security, auth, observability, and error handling.
- 2FA hardened with constant-time comparison and improved recovery codes.
- Social SSO extension added with PKCE and ID token verification.
- Pre-compiled route regex patterns for faster matching.
- Method-indexed route matching for reduced lookup time.
- Lazy JSON parsing memoization on Request objects.
- Pre-resolved middleware pipeline on first request.

## [1.0.0-rc.8] - 2026-02-08

### Added

- 7 `make:*` CLI scaffolding commands (controller, middleware, migration, model, extension, module, command).
- `help` command, version parsing, `make:extension`, and `remove:*` commands.
- Cache-warmup and ADR governance CI gates.
- 10 architecture decision records (ADR-0001 through ADR-0010).
- Boundary enforcement with Deptrac per-module layers and staged `#[Api]` enforcement.
- Route cache wired into kernel boot with config cache allowlist.
- Boot profiler with hrtime instrumentation.
- Deploy check severity overrides and all checks registered.
- `cache:warmup` alias and `optimize:validate` CI command.

### Changed

- Studio extracted into first-party extension.
- `.env` parser updated to support `export` prefix and inline comments.
- MySQL DSN includes charset parameter.
- Middleware registry `resolve()` includes recursion guard.
- CSRF error responses content-negotiated.
- Audit HMAC chain continuity restored across process restarts.
- Observability uses route patterns instead of raw paths for metric labels.
- Sensitive headers scrubbed in development error renderer.
- System-wide primitives promoted for regulated systems.

## [1.0.0-rc.7] - 2026-02-07

### Added

- Vendor-agnostic payments extension with modular monolith architecture and vertical slices.
- Enforceable architecture rules with Deptrac and PHPUnit boundary tests.
- 207 additional tests for exceptions, configs, deploy checks, and observability.

### Changed

- Coverage threshold maintained at 74% with expanded test suites.
- API snapshot formatted with Prettier.

## [1.0.0-rc.6] - 2026-02-07

### Added

- Persistent HTTP runtime with `runtime:serve` command (socket-based worker, configurable concurrency).
- Benchmark profiles for persistent runtime (warm boot, steady state).
- `ext-sockets` added to `composer.json` suggestions.

### Changed

- Code quality improvements across source files based on Qodana findings.
- Redundant Qodana suppressions replaced with proper code fixes.

## [1.0.0-rc.5] - 2026-02-06

### Added

- PHP 8.x feature matrix adoption with property hooks, asymmetric visibility, and `array_find`.
- JIT, preloading, and OPcache deploy checks with security hardening.
- Benchmark dashboard with profile comparison charts.
- Memory breakdown and warm-boot metrics in benchmark worker.
- `key:generate` command with `--write` flag for master key management.
- FrameworkCache wired into kernel for `optimize:*` commands.
- Benchmark event types, payloads, and aggregator.

### Changed

- OPcache/JIT/preload profile matrix added to benchmark CI job.

## [1.0.0-rc.4] - 2026-02-06

### Changed

- All PHPUnit notices, warnings, and deprecations resolved.
- 319 Qodana static analysis issues fixed across the codebase.

## [1.0.0-rc.3] - 2026-02-05

### Added

- Pulsar Studio observability subsystem with real-time event streaming and local viewer.
- Console Overview overhaul with sparklines, progress bars, and enhanced design.
- Studio module comprehensive unit tests.
- ESLint configured for TypeScript parsing.

### Changed

- PRD and README aligned with unified product stance.
- Contradictory Qodana `@throws` inspections resolved.
- Version bumped to 1.0.0-rc.3.

## [1.0.0-rc.2] - 2026-02-05

### Breaking

- **MetricsConfig property rename**: `prometheusEnabled` to `exporterEnabled`, `prometheusEndpoint` to `exporterEndpoint`. The `fromArray()` factory accepts both the new `'openmetrics'` and legacy `'prometheus'` exporter keys for backward compatibility. Update any code that directly accesses these properties.

### Changed

- `PrometheusExporter` renamed to `OpenMetricsExporter` (internal class, not a public API break). Docblocks updated to reference OpenMetrics text exposition format 0.0.4.
- Config stub `config/observability.php` uses `'openmetrics'` exporter key (legacy `'prometheus'` key still accepted by the DTO factory).
- Performance benchmark CI job is now advisory and does not block merges. Results appear in PR job summary and are uploaded as a 14-day build artifact for human review.
- Vendor product names removed from README and observability.md in favor of standards-based or generic phrasing.

### Added

- Public API snapshot system: `tools/api/generate-snapshot.php` generates a deterministic JSON snapshot of all `#[Api]` and `#[Internal]` annotated types. `PublicApiSnapshotTest` verifies the committed snapshot matches the codebase. Run `composer api:snapshot` to regenerate.
- KernelBootPipelineTest (11 tests) covering config-conditional service creation branches.
- OpenMetricsExporter edge case tests: histogram with labels, escaping, negative gauges, empty histograms.
- TenantAwareConnectionManager tests: SeparateConnection strategy, disconnect delegation, prefix edge cases.
- FeatureFlagManager tests: percentage fallback to tenantId, contextual priority, allFlags retrieval.
- RetryPolicy tests: fromConfig factory, jitter with small base delay, single-attempt policy.

### Removed

- `ApiAttributeDiscoveryTest` (replaced by snapshot-based `PublicApiSnapshotTest`).

## [1.0.0-rc.1] - 2026-02-05

### Added

- `#[Api]` and `#[Internal]` attributes for public API boundary enforcement.
- 16 API compatibility test suites (170+ annotated public types, 142 tests, 551 assertions).
- API attribute discovery meta-test ensuring no unexpected classes have `#[Api]`.
- PHPBench performance benchmark suite (7 benchmark files, 35 subjects) with budget assertions.
- Performance budgets CI job (`php-benchmark`) enforcing regression detection.
- `composer bench` and `composer bench:ci` scripts for running benchmarks.
- 4 E2E test files: FullRequestLifecycle, BootPipeline, SecurityPipeline, ObservabilityPipeline.
- 6 new documentation files: install.md, extensions.md, cli-reference.md, upgrade.md, public-api.md, performance-budgets.md.

### Changed

- Version bumped from 0.9.0 to 1.0.0-rc.1.
- Coverage threshold raised from 55% to 70%.
- Benchmarks migrated from `benchmarks/` to `tests/Benchmark/` with PHP 8 attributes.
- Updated architecture.md, repository-structure.md, php-feature-matrix.md, README.md.

### Internal

- 161 source files annotated with `#[Api]` or `#[Internal]` attributes.
- 10 classes marked as `#[Internal]`: Kernel, Version, ConfigManager, ConfigOverrides, Application, ExtensionRegistry, ExtensionBootstrap, ExtensionLoader, MiddlewarePipeline, MiddlewareRegistry.

## [0.9.0] - 2026-02-04

### Added

- Multi-tenancy with pluggable tenant resolution (header, subdomain, path prefix) and database isolation strategies (prefix, separate connection, shared).
- Feature flags with boolean, percentage (deterministic hash-based rollout), and contextual evaluation.
- Job scheduler with cron expression parsing and scheduled job execution.
- Self-healing and resilience primitives: retry policies, circuit breakers, health checks, and repair system.
- Console commands: `scheduler:tick`, `scheduler:list`, `health:check`, `health:repair`.
- Kernel integration for all four enterprise services.
- Config stubs: `config/tenancy.php`, `config/features.php`, `config/scheduler.php`, `config/resilience.php`.

## [0.8.0] - 2026-02-04

### Added

- Authentication system with password hashing (Argon2id), session-based auth guards, and remember-me tokens.
- Authorization with role-based (RBAC) and attribute-based (ABAC) access control.
- Two-factor authentication baseline with TOTP and recovery codes.
- Password reset and email verification flows.
- AuthConfig, GuardConfig, and TwoFactorConfig DTOs.
- AuthException with factory methods for all authentication errors.
- Kernel integration: `createAuthServices()` in boot pipeline.

## [0.7.0] - 2026-02-03

### Added

- Database abstraction layer with `ConnectionInterface` and `PdoConnection` (lazy PDO initialization, typed bindings).
- Driver enum (`MySQL`, `PostgreSQL`, `SQLite`) with `buildDsn()`, `defaultPort()`, and `supportsSavepoints()`.
- ConnectionManager for managing multiple named database connections with caching.
- Typed Row/Result value objects: `Row` with `getInt()`, `getString()`, `getBool()`, `getFloat()`, nullable variants; `Result` with `first()`, `firstOrFail()`, `pluck()`, `map()`, `isEmpty()`.
- Transaction API with savepoint-based nesting (depth 0 = real transaction, depth > 0 = savepoints).
- Statement wrapper for reusable prepared statements with fluent binding.
- DatabaseException with static factory methods for all database error scenarios.
- DatabaseConfig and ConnectionConfig readonly DTOs with `fromArray()` factories and environment variable overrides.
- Migration system: `MigrationInterface` (anonymous-class format), `MigrationRepository` (file discovery), `MigrationRunner` (batch-based tracking, `runPending`, `rollbackLastBatch`, `rollbackTo`, `reset`).
- Console commands: `migrate:run`, `migrate:rollback` (with `--all` for reset), `migrate:status`, `migrate:create`.
- Kernel integration: `createDatabaseServices()` in boot pipeline (optional, only when `config/database.php` exists).
- Config stub: `config/database.php` with MySQL, PostgreSQL, and SQLite connection templates.
- CI services: MySQL 8.0 and PostgreSQL 16 in GitHub Actions with `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite` extensions.
- `ext-pdo` added to `composer.json` requirements.

## [0.6.0] - 2026-02-03

### Added

- Security baseline v1: session hardening, CSRF protection, secure headers defaults.
- Crypto/key management primitives (libsodium: HMAC, KDF, secretbox).
- Audit logging subsystem (HMAC-chained tamper-evident entries).
- SecurityConfig, SessionConfig, CsrfConfig, SecurityHeadersConfig DTOs.
- SecurityException with factory methods for all security errors.
- Kernel integration: `createSecurityServices()` in boot pipeline.

## [0.5.0] - 2026-02-03

### Added

- Observability suite v1: metrics collector, tracing core, error grouping/reporting.
- OpenMetrics text exposition format exporter.
- Structured logging with channel-based configuration.
- Diagnostics route (`/_pulsar/diagnostics`).

## [0.4.0] - 2026-02-03

### Added

- Middleware system finalized: named groups, aliases, MiddlewareRegistry.
- Request convenience methods: `all()`, `input()`, `json()`, `wantsJson()`.
- Request validation system (typed): Validator, 11 built-in rules, ValidationMiddleware.
- JSON content-negotiation in ExceptionHandler.
- `Response::validationError()` factory (422 JSON).
- Route constraints: per-parameter regex patterns enforced during matching.
- Host-based routing: exact or parameterized host matching on routes and groups.
- Rate-limit MVP: fixed-window in-memory RateLimiter + RateLimitMiddleware (429 + Retry-After).

## [0.3.0] - 2026-02-03

### Added

- Typed config system with environment variable loading.
- Cache layer and bootstrap cache priming.
- Error handling modes: development vs production, safe error pages.
- Structured logging baseline.

## [0.2.0] - 2026-02-03

### Added

- `bin/pulsar` CLI with `init`, scaffold module/extension, show routes, and diagnostics commands.
- Extension manifest (`pulsar.json`) with discovery and lifecycle management.
- Deterministic boot pipeline (register, boot).

## [0.1.0] - 2026-02-02

### Added

- Kernel lifecycle with request/response flow.
- PSR-11 compatible container (MVP).
- Router MVP with static routes, groups, and middleware pipeline.
- Minimal HTTP abstraction.
- Example app in `examples/hello-world`.

## [0.0.1] - 2026-02-02

### Added

- Repository structure and documentation skeleton.
- CI workflows (PHP + TS) with quality gate scripts.
- Toolchain configured: PHPUnit, PHPStan, Psalm, PHP-CS-Fixer, Rector, Qodana, TypeScript, ESLint, Prettier, Vitest.
- `scripts/qa.sh` as single entrypoint for local and CI quality checks.
