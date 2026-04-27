# pulsar-orm-mysql

MySQL / MariaDB driver for pulsar-orm via sqlx-mysql.

## What

Implements the `pulsar-orm` driver trait set against MySQL 8.0+ / MariaDB 10.6+ via `sqlx-mysql`. Supports JSON columns, generated columns, full-text search, INSERT...ON DUPLICATE KEY UPDATE for upsert semantics. Connection pooling via `sqlx::Pool`.

## Why

MySQL/MariaDB remain the dominant database in many regulated-domain legacy estates (banking core systems, healthcare EHRs). Pulsar must support these without forcing a Postgres migration. Splitting the driver per Decision 2.51 keeps the MySQL-specific code path independently auditable.

## How

`pulsar-orm-mysql` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Composition root selects via `pulsar_orm::Driver::MySql(pool)`.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.12.2 + Section V.0.9-bis.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
