# Changelog

All notable changes to Pulsar Framework are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
