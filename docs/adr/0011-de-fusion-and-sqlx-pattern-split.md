# ADR-0011: Dé-fusion of v2.2 meta-crates + sqlx-pattern driver split → 76 crates

* **Status:** Accepted
* **Date:** 2026-04-27
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.51 (Workspace size: 76 first-party crates), Decision 2.32 (Extended crate catalogue — was "75+", now "76 exactly"), Decision 2.22 (Modular monolith with hexagonal ports-and-adapters)
* **Sprint:** Sprint 0.9-bis (v2.3 reconciliation closure)
* **Supersedes:** Section 16.14 v2.2 consolidation rationale (now reverse-applied per this ADR)

## Context

The v2.2 reconciliation consolidated several adjacent sub-modules into four meta-crates to reduce the workspace member count from ~70 hypothetical crates down to exactly 53:

* `pulsar-guard` consolidated csrf + sri + incident + ratelimit + resilience + ssrf-guard.
* `pulsar-realtime` consolidated websocket + sse + webtransport + broadcasting.
* `pulsar-orchestration` consolidated workflow + saga.
* `pulsar-cluster` consolidated supervisor + service-discovery.

The v2.2 rationale was "prevent crate-count inflation while preserving sub-module clarity inside the meta-crate". An interactive validation pass on the v2.2 audit findings (per the v2.3 reconciliation) revealed three problems with this rationale:

1. **CVE blast-radius is collateral.** When an upstream advisory (e.g. a CSRF-token-comparison defect in a dependency) lands in a meta-crate, every consumer of any sub-module of that meta-crate appears in the dependency graph as affected. Downstream procurement teams reading SBOMs cannot distinguish "CSRF-affected" from "SRI-affected" because both are routed through the same `pulsar-guard` crate.

2. **Semver granularity is too coarse.** When `pulsar-realtime` ships a breaking change to its WebSocket API, downstream applications using only Server-Sent Events take the breaking change too — even though their code surface is unchanged. The MAJOR bump on the meta-crate forces SemVer churn across uninvolved consumers.

3. **Audit-cycle granularity is too coarse.** When a regulator auditing `pulsar-csrf` requests evidence of CSRF-specific test discipline, the response must include the entire `pulsar-guard` test suite. Auditors cannot scope their evaluation to the CSRF surface.

The same three problems apply at higher amplitude to crates with multiple backend implementations (`pulsar-orm` against postgres + mysql + sqlite + clickhouse ; `pulsar-cloud` against AWS + Azure + GCP + OCI ; `pulsar-storage` against S3 + Azure Blob + GCS + filesystem). A postgres-specific CVE forces the entire `pulsar-orm` consumer base to the patched version even when the consumer uses only the SQLite driver.

The Rust ecosystem precedent for the right pattern is well-established:

* **sqlx** — `sqlx-core` + `sqlx-postgres` + `sqlx-mysql` + `sqlx-sqlite` (each a separate crate; consumers depend on what they need).
* **AWS SDK Rust** — `aws-sdk-s3` + `aws-sdk-dynamodb` + `aws-sdk-lambda` + ... (per-service crates).
* **tower** — `tower` core + `tower-http` + `tower-load` + `tower-balance` + `tower-discover` + ... (per-middleware crates).
* **tracing-subscriber** — `tracing-subscriber` + per-formatter sub-crates.

## Decision

The v2.2 consolidations are **reverse-applied** in v2.3, and the **sqlx-pattern driver split** is applied to the three multi-backend crates:

**Dé-fusion (+10 net):**

| v2.2 meta-crate | v2.3 sub-modules |
|-----------------|------------------|
| `pulsar-guard` | `pulsar-csrf` + `pulsar-sri` + `pulsar-incident` + `pulsar-ratelimit` + `pulsar-resilience` + `pulsar-ssrf-guard` |
| `pulsar-realtime` | `pulsar-websocket` + `pulsar-sse` + `pulsar-webtransport` + `pulsar-broadcasting` |
| `pulsar-orchestration` | `pulsar-workflow` + `pulsar-saga` |
| `pulsar-cluster` | `pulsar-supervisor` + `pulsar-service-discovery` |

**Sqlx-pattern driver split (+12 net):**

| Core crate | Driver crates added |
|------------|---------------------|
| `pulsar-orm` | `pulsar-orm-postgres` + `pulsar-orm-mysql` + `pulsar-orm-sqlite` + `pulsar-orm-clickhouse` |
| `pulsar-cloud` | `pulsar-cloud-aws` + `pulsar-cloud-azure` + `pulsar-cloud-gcp` + `pulsar-cloud-oci` |
| `pulsar-storage` | `pulsar-storage-s3` + `pulsar-storage-azure` + `pulsar-storage-gcs` + `pulsar-storage-fs` |

**HACL\* FFI binding (+1 net per ADR-0009):** `pulsar-crypto-hacl-bindings`.

**Total: 53 v2.2 + 10 dé-fusion + 12 sqlx-pattern + 1 HACL FFI = 76 crates.**

The v2.2 consolidation rationale ("prevent crate-count inflation") is rejected in favour of:

* **Granular CVE blast-radius** — each crate has its own RustSec advisory page + downstream consumer set.
* **Granular semver discipline** — a breaking change in one sub-module bumps only that sub-module's MAJOR.
* **Granular audit cycle** — auditors can scope evaluations to a specific functional surface.
* **Ecosystem precedent alignment** — matches sqlx + tower + AWS SDK Rust + tracing.

The meta-crate `pulsar-framework` re-exports the unfused sub-modules in place of the v2.2 meta-crates (so the public façade for downstream applications remains stable: `pulsar_framework::guard::*` re-exports `pulsar_csrf::*` + `pulsar_sri::*` + ... internally).

## Consequences

### Positive

* CVE blast-radius is per-crate, not per-meta-crate. SBOMs published per Decision 2.57 are surgical.
* Semver is per-crate. Breaking changes do not propagate beyond their actual scope.
* Audit cycles are per-crate. Regulators evaluate at the right granularity.
* Driver crates can adopt different MSRV / different optional features / different feature flags without affecting siblings.
* Ecosystem alignment with sqlx + tower + AWS SDK Rust reduces contributor surprise.

### Negative

* Workspace member count grows from 53 to 76 (+43 %). Build-time growth is sub-linear (incremental cargo builds touch only changed crates) but cold-build time grows.
* Dependency-graph diagram becomes denser — Section IV crate-dependency Mermaid diagram needs careful layering to remain readable.
* `cargo deny` ruleset must enumerate all 76 crates rather than 53.
* CI matrix grows: per-crate `cargo test --package` + `cargo doc --package` jobs grow from 53 to 76 (mitigated by `cargo nextest run --workspace` which is single-job).

### Neutral

* Trusted Publisher OIDC publishes 76 crates per release instead of 53 — same single-job in `publish.yml` but the iteration is longer.
* `cargo machete` (unused-dep check) covers 76 crates instead of 53.
* The four removed meta-crates' v0.0.1-alpha.0 release on crates.io is **yanked** at the v0.0.2-alpha.0 publish (the namespace is preserved but the release is marked unsuitable for use; downstream is directed to the un-fused replacements via the README).

## Alternatives considered

* **Stay at 53 crates per v2.2**.
  Rejected per the three context problems (CVE blast-radius, semver coarseness, audit-cycle coarseness).
* **Re-decompose to ~60-70 (partial dé-fusion only)**.
  Considered. User validation at the v2.3 reconciliation interactive pass selected "tout dé-fusionner" — the partial path leaves the same problems in the un-de-fused meta-crates.
* **Maximalist split to ~90+ (every sub-feature is a crate)**.
  Rejected. Cache backends (`pulsar-cache-redis`, `pulsar-cache-memcached`, `pulsar-cache-memory`), mail backends (`pulsar-mail-smtp`, `pulsar-mail-sendgrid`, `pulsar-mail-ses`, `pulsar-mail-mailgun`), notification backends (web-push, FCM, APNS) all have uniform security posture across backends — the marginal CVE-isolation benefit does not justify the additional surface. Application infrastructure backends use Cargo features for backend selection, which is the pragmatic pattern when backends share security posture.
* **Rename the meta-crates rather than fuse/un-fuse** (e.g. `pulsar-security-controls` renamed but unchanged scope).
  Rejected. The problem is the consolidation, not the naming.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.51 + 2.32 + 2.22, Section IV Workspace Layout (76 crates), Section 16.14 (v2.2 consolidation rationale, now superseded), Section XII success metrics (76 crates row).
* Risk register entries: R-019 (cross-crate API consistency).
* Related ADRs: ADR-0002 (modular monolith with hexagonal ports — the architectural pattern this ADR refines at the granularity dimension), ADR-0006 (crates.io namespace), ADR-0009 (HACL\* crypto adds the +1 to reach 76).
* External:
  * sqlx workspace: https://github.com/launchbadge/sqlx
  * AWS SDK Rust: https://github.com/awslabs/aws-sdk-rust
  * tower workspace: https://github.com/tower-rs/tower
  * tracing workspace: https://github.com/tokio-rs/tracing

## Compliance mapping

* **NIST SP 800-218 SSDF — PS.2** (provide a mechanism for verifying software release integrity) — per-crate SBOMs are surgical at the CVE level. Downstream consumers verify what they actually consume.
* **EU CRA Annex I § 2(d)** (vulnerabilities — having processes in place to address vulnerabilities) — per-crate advisory channels per RustSec mean a vulnerability in `pulsar-csrf` is surfaced as a `pulsar-csrf` advisory, not as a `pulsar-guard` advisory affecting six unrelated sub-modules.
* **NIS2 Art. 21(2)(d)** (supply-chain security) — per-crate signing + per-crate SBOM via Cosign + CycloneDX (per ADR-0007 + Decision 2.57) is the supply-chain-security primitive at the right granularity.
* **OpenSSF Best Practices Badge — Gold tier** (Section 16.12.8 target) — per-crate semver discipline + per-crate CVE history + per-crate test coverage are the gold-tier criteria. Granular crates make these criteria mechanically verifiable.
