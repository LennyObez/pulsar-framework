# Changelog

All notable changes to Pulsar Framework are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.0] — 2026-02-04

### Added

- **Multi-tenancy** with pluggable tenant resolution (header, subdomain, path prefix) and database isolation strategies (prefix, separate connection, shared).
  - `Tenant` readonly value object with `fromArray()` factory.
  - `TenantContext` for request-scoped tenant state.
  - `TenantResolverInterface` with three built-in resolvers: `HeaderTenantResolver`, `SubdomainTenantResolver`, `PathPrefixTenantResolver`.
  - `TenantResolutionMiddleware` for automatic tenant resolution and request attribute injection.
  - `TenantAwareConnectionManager` decorates `ConnectionManagerInterface` with tenant-scoped database isolation.
  - `TenancyConfig` and `TenantDatabaseConfig` readonly DTOs with `fromArray()` factories and `TENANCY_ENABLED` env override.
  - `TenancyException` with static factories: `tenantNotResolved()`, `tenantNotFound()`, `invalidConfiguration()`.
  - Config stub: `config/tenancy.php`.

- **Feature flags** with boolean, percentage (deterministic hash-based rollout), and contextual evaluation.
  - `FlagDefinition` readonly value object with `fromArray()` / `toArray()`.
  - `FlagContext` with tenant, user, environment, and custom attribute support.
  - `FlagEvaluation` and `FlagEvaluationReason` enum for audit-grade evaluation records.
  - `FlagStorageInterface` with two backends: `InMemoryFlagStorage` and `FileFlagStorage` (JSON).
  - `FlagEvaluationLog` for in-memory audit trail of all evaluations.
  - `FeatureFlagManager` with deterministic percentage rollout via `crc32(flagName . identifier) % 100`.
  - `FeatureFlagConfig` readonly DTO with `fromArray()` factory and `FEATURE_FLAGS_ENABLED` env override.
  - `FeatureFlagException` with static factories: `storageError()`, `invalidDefinition()`.
  - Config stub: `config/features.php`.

- **Job scheduler** with cron expression parsing and scheduled job execution.
  - `Schedule` readonly value object with static factories: `everyMinute()`, `everyFiveMinutes()`, `hourly()`, `daily()`, `dailyAt()`, `weekly()`, `monthly()`, `cron()`.
  - `CronFields` parser supporting wildcards, ranges, steps, and comma-separated values.
  - `JobInterface` and `CallbackJob` for closure-based jobs.
  - `JobRegistry` with due-job filtering and duplicate detection.
  - `Scheduler` with `tick()` for executing all due jobs and `runJob()` for individual execution.
  - `SchedulerTickResult` and `JobResult` readonly result objects.
  - `SchedulerConfig` readonly DTO with `fromArray()` factory and `SCHEDULER_ENABLED` env override.
  - `SchedulerException` with static factories: `jobNotFound()`, `executionTimeout()`, `invalidCronExpression()`, `duplicateJob()`.
  - Console commands: `scheduler:tick`, `scheduler:list`.
  - Config stub: `config/scheduler.php`.

- **Self-healing / resilience** primitives: retry policies, circuit breakers, health checks, and repair system.
  - `RetryPolicy` with exponential backoff, jitter, and configurable max attempts.
  - `RetryResult` readonly value object with `success()` and `exhausted()` factories.
  - `CircuitBreaker` with state machine (Closed / Open / HalfOpen), failure/success thresholds, and timeout-based recovery.
  - `CircuitBreakerRegistry` for named circuit breaker management (create-or-return semantics).
  - `HealthCheckInterface` with built-in `DatabaseHealthCheck` (`SELECT 1` with response time measurement).
  - `HealthCheckRunner` for executing all registered checks and producing `HealthReport`.
  - `RepairJobInterface` with `RepairRunner` for diagnosis and automated repair.
  - `ResilienceConfig` composing `RetryConfig`, `CircuitBreakerConfig`, and `HealthCheckConfig` sub-DTOs.
  - `ResilienceException` with static factories: `circuitOpen()`, `retryExhausted()`, `healthCheckFailed()`, `repairFailed()`.
  - Console commands: `health:check`, `health:repair`.
  - Config stub: `config/resilience.php`.

- **Kernel integration**: `createTenancyServices()`, `createFeatureFlagServices()`, `createSchedulerServices()`, `createResilienceServices()` in boot pipeline.
- **ConfigManager**: optional loading of `tenancy.php`, `features.php`, `scheduler.php`, `resilience.php`.

## [0.7.0] — 2026-02-03

### Added

- **Database abstraction layer** with `ConnectionInterface` and `PdoConnection` (lazy PDO initialization, typed bindings).
- **Driver enum** (`MySQL`, `PostgreSQL`, `SQLite`) with `buildDsn()`, `defaultPort()`, and `supportsSavepoints()`.
- **ConnectionManager** for managing multiple named database connections with caching.
- **Typed Row/Result value objects**: `Row` with `getInt()`, `getString()`, `getBool()`, `getFloat()`, nullable variants; `Result` with `first()`, `firstOrFail()`, `pluck()`, `map()`, `isEmpty()`.
- **Transaction API** with savepoint-based nesting (depth 0 = real transaction, depth > 0 = savepoints).
- **Statement wrapper** for reusable prepared statements with fluent binding.
- **DatabaseException** with static factory methods for all database error scenarios.
- **DatabaseConfig** and **ConnectionConfig** readonly DTOs with `fromArray()` factories and environment variable overrides (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CONNECTION`).
- **Migration system**: `MigrationInterface` (anonymous-class format), `MigrationRepository` (file discovery), `MigrationRunner` (batch-based tracking, `runPending`, `rollbackLastBatch`, `rollbackTo`, `reset`).
- **Console commands**: `migrate:run`, `migrate:rollback` (with `--all` for reset), `migrate:status`, `migrate:create`.
- **Kernel integration**: `createDatabaseServices()` in boot pipeline (optional — only when `config/database.php` exists).
- **Config stub**: `config/database.php` with MySQL, PostgreSQL, and SQLite connection templates.
- **CI services**: MySQL 8.0 and PostgreSQL 16 in GitHub Actions with `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite` extensions.
- **`ext-pdo`** added to `composer.json` requirements.
- **Stub directory**: `database/migrations/.gitkeep`.

## [0.6.0] — 2025-01-15

### Added

- Security baseline v1: session hardening, CSRF protection, secure headers defaults.
- Crypto/key management primitives (libsodium: HMAC, KDF, secretbox).
- Audit logging subsystem (HMAC-chained tamper-evident entries).
- SecurityConfig, SessionConfig, CsrfConfig, SecurityHeadersConfig DTOs.
- SecurityException with factory methods for all security errors.
- Kernel integration: `createSecurityServices()` in boot pipeline.

## [0.5.0] — 2025-01-01

### Added

- Observability suite v1: metrics collector, tracing core, error grouping/reporting.
- Prometheus exposition format exporter.
- Structured logging with channel-based configuration.
- Diagnostics route (`/_pulsar/diagnostics`).
