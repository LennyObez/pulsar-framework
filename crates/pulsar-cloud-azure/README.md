# pulsar-cloud-azure

Azure adapter for pulsar-cloud — Blob Storage, Key Vault, Functions, Communication Services.

## What

Implements the `pulsar-cloud` adapter trait set against Azure via `azure_*` crates. Surfaces: Blob Storage for object store, Key Vault for envelope encryption + secret rotation, Azure Functions for event-driven hooks, Communication Services for transactional mail, Managed Identity for credential-free authentication on Azure compute.

## Why

Azure is the dominant cloud in EU public sector + financial services (especially after several large bank-vendor agreements 2024-2026). Splitting per Decision 2.51 keeps the Azure-specific surface independently auditable + version-pinnable. EU data-sovereignty regulations often require Azure-region-bound deployments specifically.

## How

`pulsar-cloud-azure` is a v2.3 sqlx-pattern driver crate per Decision 2.51. Default credential chain: Managed Identity → CLI → Service Principal.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0`. See [`docs/plan.md`](../../docs/plan.md) Section IV.48.2 + Section V.3E.2.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
