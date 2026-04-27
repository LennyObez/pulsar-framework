# pulsar-orm-sqlite

SQLite driver for pulsar-orm via sqlx-sqlite — single-file embedded + WAL.

## What

Implements the `pulsar-orm` driver trait set against SQLite 3.45+ via `sqlx-sqlite`. Supports WAL mode for concurrent readers, FTS5 for full-text search, JSON1 for semi-structured columns. Single-file embedded mode for self-hosted deployments + CLI tools.

## Why

SQLite is the canonical embedded database — every `pulsar-cli` self-test rig, every developer machine bootstrap, every deployable demo can run end-to-end without a managed DB. The PHP era used SQLite for the demo + dev surface; Pulsar continues that with a first-class driver.

## How

`pulsar-orm-sqlite` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Composition root selects via `pulsar_orm::Driver::Sqlite(pool)`.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.12.3 + Section V.0.9-bis.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
