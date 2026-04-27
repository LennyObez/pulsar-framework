# pulsar-storage-fs

Local filesystem backend for pulsar-storage — single-node + dev + air-gapped deployments.

## What

Implements the `pulsar-storage` backend trait set against the local filesystem via `tokio::fs`. Supports atomic writes (write to temp + rename), checksummed reads (SHA-256 sidecar files), per-tenant subdirectory layout, file-locking via `fs2`. Suitable for single-node deployments, developer machines, air-gapped environments where no cloud-storage is reachable.

## Why

Not every Pulsar deployment runs in the cloud. Government air-gapped systems, single-node SMB deployments, developer environments, and CI test rigs all need a storage backend that works without network. The filesystem driver fills this gap with the same trait surface as the cloud drivers, so application code is identical regardless of backend.

## How

`pulsar-storage-fs` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Atomic-write discipline (write-to-temp + rename) is enforced by the trait implementation, not delegated to caller convention.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.21.4 + Section V.3E.X.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
