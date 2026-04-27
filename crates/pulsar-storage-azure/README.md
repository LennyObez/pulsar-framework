# pulsar-storage-azure

Azure Blob Storage backend for pulsar-storage.

## What

Implements the `pulsar-storage` backend trait set against Azure Blob Storage via `azure_storage_blobs`. Supports block + page + append blob modes, server-side encryption with Microsoft-managed or customer-managed keys, lifecycle management, immutability policies (legal hold, time-based retention) for regulatory archives.

## Why

Azure Blob Storage is the canonical storage backend for Azure deployments + the typical regulatory choice for EU public sector workloads. Immutability policies (legal hold + time-based retention) are required for several regulated domains (financial services audit logs, healthcare records). Splitting per Decision 2.51 keeps the Azure code path independently auditable.

## How

`pulsar-storage-azure` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Authentication via `pulsar-cloud-azure` Managed Identity by default; SAS tokens supported as fallback.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.21.2 + Section V.3E.X.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
