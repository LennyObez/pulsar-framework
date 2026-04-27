# pulsar-storage-gcs

Google Cloud Storage backend for pulsar-storage.

## What

Implements the `pulsar-storage` backend trait set against Google Cloud Storage via `google-cloud-storage`. Supports composite uploads, customer-managed encryption keys (CMEK), object versioning, retention policies, signed URLs.

## Why

GCS is the canonical storage backend for GCP deployments + a frequent regulatory choice in healthcare (BAA-eligible). CMEK + retention policies cover the regulatory invariants. Splitting per Decision 2.51 keeps the GCS-specific code path independently auditable.

## How

`pulsar-storage-gcs` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Authentication via `pulsar-cloud-gcp` Workload Identity by default.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.21.3 + Section V.3E.X.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
