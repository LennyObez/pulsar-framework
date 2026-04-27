# pulsar-cloud-oci

Oracle Cloud Infrastructure adapter for pulsar-cloud — Object Storage, Vault, Functions.

## What

Implements the `pulsar-cloud` adapter trait set against Oracle Cloud Infrastructure via direct REST API + Signature v1 request signing (no first-party Rust SDK exists in 2026 so REST-direct is the pragmatic path). Surfaces: Object Storage for storage, Vault for envelope encryption, Functions for event-driven hooks, Resource Principal for credential-free authentication on OCI compute.

## Why

OCI is the standard cloud for many financial services + government deployments where Oracle's database + EBS + ERP estate already runs. Splitting per Decision 2.51 makes the OCI-specific code path independently version-pinnable + the REST signing primitive (which uses HACL\* via `pulsar-kernel`) is isolated from sibling cloud drivers.

## How

`pulsar-cloud-oci` is a v2.3 sqlx-pattern driver crate per Decision 2.51. The lack of a first-party Rust OCI SDK is a well-known gap in 2026; Pulsar handles it via direct REST calls signed with HACL\*-provided RSA primitives.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.48.4 + Section V.3E.2.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
