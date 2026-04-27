# pulsar-orm-clickhouse

ClickHouse driver for pulsar-orm — OLAP analytical workloads.

## What

Implements the `pulsar-orm` driver trait set against ClickHouse via the official Rust client. Optimised for OLAP workloads: bulk inserts via Native protocol + columnar reads + materialised view + projection support. Companion to the OLTP drivers (postgres / mysql / sqlite) — a Pulsar deployment typically runs both.

## Why

ClickHouse is the OLAP database per Decision 2.17 (optional but framework-supported). Audit log replay + analytics dashboards + cohort exports run against ClickHouse where the OLTP database would buckle under analytical query loads. Splitting per Decision 2.51 keeps the analytical code path independently auditable.

## How

`pulsar-orm-clickhouse` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Composition root selects via `pulsar_orm::Driver::ClickHouse(client)`.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.12.4 + Section V.0.9-bis.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
