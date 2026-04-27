# pulsar-cloud-gcp

GCP adapter for pulsar-cloud — Cloud Storage, Cloud KMS, Cloud Functions, SecretManager.

## What

Implements the `pulsar-cloud` adapter trait set against GCP via the `google-cloud-*` crates. Surfaces: Cloud Storage for object store, Cloud KMS for envelope encryption, SecretManager for configuration, Cloud Functions for event-driven hooks, Workload Identity for credential-free authentication on GCP compute.

## Why

GCP has strong adoption in European public-sector + healthcare (especially Workload Identity Federation for AWS↔GCP cross-cloud). Splitting per Decision 2.51 keeps the GCP-specific surface independently auditable.

## How

`pulsar-cloud-gcp` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Default credential chain: Workload Identity → ADC (Application Default Credentials) → Service Account JSON.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.48.3 + Section V.3E.2.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
