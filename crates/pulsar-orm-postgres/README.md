# pulsar-orm-postgres

PostgreSQL driver for pulsar-orm via sqlx-postgres with pgvector + JSONB support.

## What

Implements the `pulsar-orm` driver trait set against PostgreSQL 16+ via `sqlx-postgres`. Supports pgvector for vector search collocation with relational data, JSONB for semi-structured columns, prepared-statement caching, advisory locks, LISTEN/NOTIFY for cache invalidation. Connection pooling via `sqlx::Pool`. Migrations driven by `sqlx::migrate!` with deterministic file naming.

## Why

PostgreSQL is the default OLTP database per Decision 2.15 (with Patroni + etcd for HA). Splitting it into its own driver crate per Decision 2.51 means a postgres-specific CVE (e.g. an upstream sqlx-postgres advisory) does not taint downstream applications using only the MySQL or SQLite driver.

## How

`pulsar-orm-postgres` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Depends on `pulsar-orm` for the driver trait set and `sqlx` with the `postgres` feature for the wire protocol implementation. Composition root selects this driver via `pulsar_orm::Driver::Postgres(pool)`.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.12.1 + Section V.0.9-bis.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
