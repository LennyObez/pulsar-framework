# ADR-0006: Crates.io `pulsar-*` namespace with meta-crate `pulsar-framework`

* **Status:** Accepted
* **Date:** 2026-04-26
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.23 (Crates.io namespace: `pulsar-*` prefix on every published crate), Decision 2.24 (Meta-crate: `pulsar-framework`)
* **Sprint:** Sprint 0.5 (initial ADR batch); namespace reservation in Sprint 0.8
* **Supersedes:** none

## Context

Pulsar Framework publishes 53 first-party Rust crates to crates.io. Three constraints shape the naming scheme:

1. **Namespace collision.** The unprefixed `pulsar` crate name is occupied by an Apache Pulsar (the messaging system) Rust client. Pulsar Framework cannot publish under `pulsar` directly.
2. **Visual grouping on crates.io.** When users search for framework-related crates, all Pulsar-provided crates should be discoverable under a single prefix.
3. **Downstream stability commitment.** Downstream applications should not have to track 53 individual crate versions. A meta-crate that re-exports the stable public surface gives downstream consumers a single dependency to pin.

Crates.io does not yet support enforced namespaces (RFC 1592 was deferred). The de-facto convention in the Rust ecosystem is **prefix-based namespacing**: `tokio-*`, `tower-*`, `hyper-*`, `serde-*`, `rustls-*`, `tracing-*`. A consistent prefix gives the same visual grouping and discoverability that an enforced namespace would provide.

## Decision

Every first-party Pulsar Framework crate published to crates.io uses the **`pulsar-`** prefix. The 53 crates per plan Section IV crate count are:

```
pulsar-framework        # meta-crate (re-exports stable public surface)
pulsar-kernel
pulsar-http
pulsar-engine
pulsar-orm
pulsar-audit
pulsar-auth
pulsar-compliance
pulsar-observability
pulsar-search
pulsar-guard
pulsar-mail
pulsar-queue
pulsar-scheduler
pulsar-cache
pulsar-config
pulsar-storage
pulsar-form
pulsar-webhook
pulsar-idempotency
pulsar-notification
pulsar-pagination
pulsar-feature-flag
pulsar-i18n
pulsar-tenancy
pulsar-dataprotection
pulsar-consent
pulsar-authz
pulsar-identity-standards
pulsar-cms
pulsar-forum
pulsar-payments
pulsar-console-api
pulsar-api
pulsar-graphql
pulsar-grpc
pulsar-mcp-server
pulsar-realtime
pulsar-ai
pulsar-ai-governance
pulsar-vector-search
pulsar-ai-agents
pulsar-live
pulsar-studio
pulsar-analytics
pulsar-accessibility
pulsar-orchestration
pulsar-cloud
pulsar-edge
pulsar-cluster
pulsar-deploy
pulsar-cli
pulsar-test
```

A meta-crate **`pulsar-framework`** re-exports the stable public surface of the constituent crates. Downstream applications depend on `pulsar-framework` rather than on individual crates; version compatibility is guaranteed within a single meta-crate minor version. The meta-crate is the **stability commitment boundary**.

The 16 crates marked "Re-exported in meta. Yes." in plan Section IV are: `pulsar-kernel`, `pulsar-http`, `pulsar-engine`, `pulsar-orm`, `pulsar-audit`, `pulsar-auth`, `pulsar-compliance`, `pulsar-observability`, `pulsar-search`, `pulsar-guard`, `pulsar-dataprotection`, `pulsar-consent`, `pulsar-authz`, `pulsar-identity-standards`, `pulsar-ai-governance`, `pulsar-orchestration`. The remaining 37 crates are imported explicitly by downstream code that needs them.

The meta-crate convention (per plan Section IV intro): inner crates do **not** depend on `pulsar-framework`; doing so would create a cyclic dependency through the meta-crate's re-exports. Inner crates depend directly on the smaller set of inner crates whose types they need (most commonly `pulsar-kernel`); the meta-crate aggregates the surface for downstream consumption. This is the standard meta-crate pattern (see e.g. `tokio`, `sqlx`).

Namespace reservation publishes all 53 crates at version `v0.0.1-alpha.0` to crates.io in Sprint 0.8. Each placeholder `README.md` states explicitly: "Placeholder release for namespace reservation. The implementation ships in 0.1.0."

## Consequences

### Positive

* Visual grouping on crates.io: all framework-provided crates surface together under `pulsar-` search.
* Downstream consumers depend on a single crate (`pulsar-framework`) and get the entire stable surface via the prelude.
* Meta-crate version becomes the framework version as a whole — semver guarantees apply at the meta level even when constituent crates evolve internally.
* Inner crates can iterate independently across phases without dragging the meta-crate version forward unnecessarily — only changes to re-exported public items bump the meta-crate.
* `pulsar-` prefix prevents accidental collision with the `pulsar` (Apache Pulsar client) crate.

### Negative

* 53 crates means 53 publish steps per release cycle; the `publish.yml` workflow handles this in dependency order, but the surface is large.
* Namespace reservation requires publishing placeholder crates that contain no production functionality at `v0.0.1-alpha.0` — adds noise to crates.io.
* If a future architectural change requires renaming a crate, the new crate name takes a new crates.io entry; the old name remains forever (crates.io has no rename or delete).
* Meta-crate re-export discipline must be maintained as new crates are added; missing a re-export silently breaks the prelude.

### Neutral

* `pulsar-framework` is reserved as the meta-crate name. The unprefixed `pulsar` remains owned by the Apache Pulsar client maintainer; Pulsar Framework will not attempt to claim it.
* Cargo workspace inheritance (`version.workspace = true`, etc.) keeps the per-crate `Cargo.toml` minimal.

## Alternatives considered

* **Three coarse meta-crates (one per layer).**
  Rejected per Decision 2.32 alternatives: coarsens version boundaries and couples unrelated subsystems (e.g. forcing every kernel crate to bump together).
* **Monolithic `pulsar` crate (with feature flags per subsystem).**
  Rejected: catastrophic compile-time cost; forced feature-flag explosion; each consumer's compile time grows with every subsystem in the framework.
* **Single `pulsar` crate without prefix.**
  Rejected: namespace already occupied by Apache Pulsar client.
* **`pf-*` or `pulsar_framework_*` prefix instead of `pulsar-`.**
  Rejected: `pf-*` is opaque and conflicts with Path Finder ecosystem on crates.io; `pulsar_framework_*` is verbose and breaks crates.io kebab-case convention.
* **Sub-namespace via `pulsar-fw-*`.**
  Rejected: doubles the prefix length without adding clarity. Existing Rust ecosystem uses single-level prefixes (tokio-, hyper-, serde-).
* **Publish to a private registry instead of crates.io.**
  Rejected: defeats the open-source distribution model. crates.io is the canonical Rust distribution channel.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.23 + 2.24 + 2.32, Section IV Workspace Layout (53-crate enumeration), Section IX release strategy (namespace reservation at Sprint 0.8).
* Risk register entries: R-019 (cross-crate API consistency).
* Related ADRs: ADR-0001 (full rewrite in Rust), ADR-0002 (modular monolith with hexagonal ports), ADR-0005 (Apache-2.0 licence).
* External:
  * crates.io. "Crates.io publishing guide." doc.rust-lang.org/cargo/reference/publishing.html.
  * RFC 1592 (deferred). "Issues with allowing rename / namespacing" — the historical proposal that did not land.
  * Apache Pulsar Rust client. crates.io/crates/pulsar.
  * Tokio meta-crate. crates.io/crates/tokio (re-exports tokio-* sub-crates).
  * sqlx meta-crate. crates.io/crates/sqlx.

## Compliance mapping

The namespace + meta-crate decision is the supply-chain provenance boundary on the public package side. Every `pulsar-*` crate published to crates.io is signed via Sigstore Cosign keyless attestation + carries a SLSA Level 4 provenance attestation + is reproducibly buildable from the same source commit. The namespace is the audit-trail anchor downstream consumers verify against.

* **SLSA Framework v1.0 — Build Level 4** — every published crate carries a build-provenance attestation generated by `.github/workflows/publish.yml` via `actions/attest-build-provenance@v1`. The `pulsar-*` namespace prefix is the discovery mechanism downstream verifiers use to enumerate "all crates Pulsar publishes" before validating their attestations collectively.
* **NIST SP 800-218 — SSDF — PS.1** (protect software code from unauthorised access and tampering) — Trusted Publisher OIDC on crates.io eliminates long-lived registry tokens; only commits signed by the maintainer's Ed25519 key trigger a publish. Namespace ownership at the crates.io level is the access-control boundary.
* **NIST SP 800-218 — SSDF — PS.2** (provide a mechanism for verifying software release integrity) — Cosign-signed crate artefacts + SBOM + attestation collectively answer "is this `.crate` what the maintainer published?".
* **NIST SP 800-218 — SSDF — PS.3** (archive and protect each software release) — Trusted Publisher publishes are immutable on crates.io; combined with reproducible-build verification this makes the published artefact corner of the supply chain auditable.
* **EU CRA (Regulation (EU) 2024/2847) Annex I § 2(d)** (vulnerabilities — having processes in place to address vulnerabilities and document publicly) — namespace ownership lets Pulsar publish CVE advisories that downstream automated tools (cargo-audit, RustSec) can reliably correlate with installed crates.
* **EU CRA Annex I § 2(e)** (provide an SBOM in a machine-readable format) — the meta-crate `pulsar-framework` is the entry point downstream tools resolve to enumerate the workspace; the SBOM emitted by `publish.yml` (CycloneDX format) covers the full transitive graph from that root.
* **NIS2 Art. 21(2)(j)** (use of multi-factor authentication) — Trusted Publisher OIDC eliminates the single-factor API token attack surface; publish authorisation is bound to GitHub-issued OIDC tokens that themselves require MFA on the maintainer account.
* **OpenSSF SCM Best Practices** — namespace squatting prevention via early reservation (Sprint 0.8 `v0.0.1-alpha.0` namespace-reservation publish) is the OpenSSF-recommended mitigation against typosquatting on the publishing side.
* **ENISA "Guidelines for Secure Open-Source Software"** (2023) — section "Naming and Disambiguation" recommends namespace prefixes for ecosystem-defining projects; the `pulsar-*` prefix on every crate is the implementation.
