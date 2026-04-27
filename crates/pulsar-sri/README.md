# pulsar-sri

Subresource Integrity (SRI) digest enforcement for inline + external assets per W3C SRI Recommendation.

## What

Computes + verifies SRI digests (`sha256` / `sha384` / `sha512`) on every external asset referenced by `pulsar-engine` templates: scripts, stylesheets, fonts, images. Emits the appropriate `integrity="<algo>-<digest>"` attribute on rendered tags + verifies digests against a build-time pre-computed manifest at runtime when assets are inlined or proxied through the application.

## Why

Tampered or mis-served assets are a long-standing supply-chain attack vector — a CDN compromise or a man-in-the-middle on plain HTTP can substitute a malicious script for the legitimate one. SRI is the W3C-standardised defence: the browser computes the loaded asset's hash and refuses to execute it if the hash does not match the `integrity` attribute. Pulsar wires SRI on by default for every template-rendered asset reference.

## How

`pulsar-sri` is a v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51. It depends on `pulsar-engine` for the template-rendering integration, on `pulsar-kernel` for the SHA-2 family digests (sourced via HACL\* per Decision 2.53), and integrates with `pulsar-storage` for the asset-pipeline manifest. The Creusot contract on the `verify_digest` invariant lands at the Sprint 1.5 sprint exit.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 1.5 (historic `pulsar-guard` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.11.2 (per-crate spec) and Section V.1.5 (implementing sprint).

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
