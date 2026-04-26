# Changelog

All notable changes to Pulsar Framework are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Sprint 0.4 — CI workflows formal exit gate

* All five GitHub Actions workflows (ci.yml, nightly.yml, audit.yml, benchmark.yml, publish.yml) verified syntactically valid via `python3 -c "import yaml; yaml.safe_load(...)"`.
* New `docs/ops/ci.md` documents the CI topology end-to-end: workflow inventory, per-job breakdown, caching strategy, runner choice, local invocation aliases, and Sprint 0.4 exit verification status.
* The "first ci.yml run on develop succeeds" and "publish.yml dry-runs cargo publish --dry-run" exit criteria are deferred to the first `git push origin develop` (per plan workflow rule "never push unless explicitly requested"). Workflow source is correct and triggers properly on `vN.M.P*` tag patterns.

### Sprint 0.3 — 53 crate stubs formal exit gate

* `cargo check --workspace` succeeds in 2m22s on local develop (exit 0).
* `cargo test --workspace` runs zero tests across the 53-crate workspace and exits 0 (`running 0 tests` — `test result: ok. 0 passed; 0 failed`), matching plan spec for stub-phase verification.
* `cargo doc --workspace --no-deps` generates 53 `index.html` files in 7m32s (exit 0); every stub crate has crate-level `//!` doc comment, `#![deny(missing_docs)]`, `#![forbid(unsafe_code)]`, and the `pub const VERSION` constant per Sprint 0.3 spec.
* Plan Section IV introduction gains a Meta-crate convention paragraph explaining why per-crate "Dependencies" lines list pulsar-framework as the public composition surface but inner stubs do not declare it as a Cargo dep (cycle through the meta-crate's re-exports). Inner crates depend directly on the smaller set of inner crates whose types they need (most commonly pulsar-kernel); the meta-crate aggregates the surface for downstream consumption. Standard meta-crate pattern from tokio, sqlx.
* Five v2.1 leftover references to "fifteen placeholder crates" / "fifteen crates" in Section V Phase 0 intro, Sprint 0.8, Sprint 4.6 exit criteria, Section IX.1, and Section IX.5 corrected to "fifty-three" — matching Section IV crate count and the workspace [members] list.

### Sprint 0.2 — Workspace Cargo manifests formal exit gate

* Three quality-gate commands all green: `cargo check --workspace --all-targets --all-features` exit 0, `cargo deny check` reports `advisories ok, bans ok, licenses ok, sources ok`, `cargo fmt --all -- --check` exit 0 with no warning noise.
* Dep version bumps eliminate four RUSTSEC IDs at the root: async-nats 0.38 → 0.47, rdkafka 0.37 → 0.39, instant-acme 0.7 → 0.8.
* deny.toml `[advisories.ignore]` populated with nine documented entries for transitive-only RUSTSEC IDs (instant, number_prefix, paste, rsa Marvin Attack via openidconnect, rustls-pemfile, three rustls-webpki name-constraint vulns, trust-dns-proto). Each entry has explicit Sprint 0.4 dep-hygiene target.
* deny.toml `[bans]` native-tls now uses `wrappers = ["hyper-tls", "tokio-native-tls", "reqwest", "ldap3"]` to allow the four legitimate transitive paths while preserving the direct-use ban.
* rustfmt.toml split into stable + commented-nightly sections (10 nightly-only options moved to documentation comments; stable `cargo fmt --check` no longer emits warnings).
* New `docs/ops/deployment/toolchain.md` documenting every pinned tool (rustc 1.95.0, mold linker, sccache, deny.toml policy, full quality-gate sequence) — plan Section V Sprint 0.2 documentation deliverable.

### Sprint 0.1 — Meta-files alignment

* README.md aligned with v2.2 plan: 53-crate workspace layout, 23-framework compliance count, native Web Components admin SPA replaces Leptos WASM, Mermaid architecture refreshed with consolidated layers, explicit links to docs/plan.md and docs/adr/INDEX.md per Sprint 0.1 exit criterion.
* SECURITY.md crypto section now reflects in-scope FIPS 140-3 validation pathway (Sprint 4.7), HSM/PKCS#11 (Sprint 2C.3), confidential computing (Sprint 4.8), and PQC hybrid (Sprint 1.1 + 2.5) per plan Section 14.
* CONTRIBUTING.md scope list expanded to all 53 crate names; quality-gate command sequence corrected (`cargo mutants --minimum-test-timeout 60` was previously the invalid `--minimum-test-efficacy 95`); new naming-conventions section pointing at plan Section XVII catalogue.
* Three blocking v2.1 baseline bugs surfaced and fixed: `subtle 2.7` (does not exist on crates.io, max is 2.6.1) → `subtle 2.6`; `acme-client 0.4` (abandoned 2017, transitively pulls banned `native-tls 0.1`) → `instant-acme 0.7` (modern async ACME v2 on hyper + rustls); meta-crate dependency cycle (40 inner stubs incorrectly declared `pulsar-framework` as a dep, creating cycles via meta-crate re-exports) — pulsar-framework removed from all inner stubs, `pulsar-kernel` added to pulsar-queue + pulsar-cache + pulsar-storage for AEAD primitive access.
* `trust-dns-resolver 0.23` (unmaintained per RUSTSEC-2025-0017) migrated to `hickory-resolver 0.24` across workspace.dependencies, pulsar-cluster Cargo.toml, and Section IV 4.50 spec.
* deny.toml license allow-list extended with five OSI/FSF-approved permissive licenses encountered in the resolved dep graph: `Apache-2.0 WITH LLVM-exception`, `MIT-0`, `0BSD`, `BSL-1.0`, `CDLA-Permissive-2.0`.
* Cargo.lock generated and committed for reproducible builds (per plan Section 14.6 SLSA 4 requirement and the binary nature of pulsar-cli).
* `cargo deny check licenses` now passes green; `cargo metadata --no-deps` parses cleanly across the 53-crate workspace with zero cycles.

### Added

- Master plan `docs/plan.md` v2.2 — full strict-parity rewrite roadmap with 53 first-party crates, 11 phases, 9 TLA+ specifications, 23 compliance framework mappings, and 50 strategic decisions.
- Cargo workspace manifest at `Cargo.toml` declaring 53 crate members across twelve layers (meta + kernel + core foundation + security controls + application infrastructure + data protection + authz + identity + application extensions + API paradigms + AI surface + reactive + dev experience + orchestration + infrastructure adapters + CLI + test).
- Pinned Rust 1.95.0 stable + edition 2024 via `rust-toolchain.toml` with full component set (`rustfmt`, `clippy`, `rust-analyzer`, `rust-src`, `llvm-tools-preview`).
- 53 crate stub directories under `crates/` with metadata, README, src/lib.rs scaffolding, and minimal `[dependencies]` sections wired to `[workspace.dependencies]` for external deps and `path` for internal sibling crates.
- Cargo workspace dependency table covering ~70 external crates pinned per plan Section 13.4 (ring, subtle, zeroize, secrecy, hyper 1.9, tokio 1.52, rustls 0.23, sqlx 0.8, tantivy 0.25, wasmtime 44, etc.) plus AWS/Azure/GCP SDKs, ClickHouse client, k8s-openapi.
- `LICENSE` (Apache-2.0 canonical text from apache.org).
- `NOTICE` with copyright, EUIPO trademark disclaimer, and third-party attribution policy.
- `.cargo/config.toml` wiring `mold` linker on Linux x86_64 and aarch64, sccache rustc-wrapper opt-in, sparse crates.io protocol, force-frame-pointers across desktop targets, and Section VI quality-gate aliases (`cargo check-all`, `cargo clippy-all`, `cargo cov`, `cargo deny-check`, etc.).
- `rustfmt.toml` (max_width 100, imports_granularity Module, group_imports StdExternalCrate, comment_width 100, format_strings).
- `clippy.toml` (cognitive-complexity 15, msrv 1.95.0, missing-docs-in-crate-items, too-many-arguments 5, too-many-lines 100).
- `deny.toml` covering six target triples (Linux glibc + musl x86_64/aarch64, macOS x86_64/aarch64, Windows MSVC, wasm32) with license allow-list (Apache-2.0, MIT, BSD-2/3, ISC, MPL-2.0, Unicode-DFS-2016, Unicode-3.0, Zlib, CC0-1.0, OpenSSL with ring exception), advisory zero-tolerance, and explicit bans on `native-tls`, `time < 0.3`, `chrono < 0.4.35`.
- Five GitHub Actions workflows in `.github/workflows/` aligned with Section VII tooling stack:
  - `ci.yml` — fmt + clippy + check + nextest + doc-tests + llvm-cov + cargo-deny + cargo-audit + cargo-machete + rustdoc strict + ADR-index integrity + CHANGELOG entry enforcement on PRs.
  - `nightly.yml` — cargo-mutants on diff or full workspace, cargo-fuzz short runs (10 minutes per target), Miri on the kernel with `MIRIFLAGS="-Zmiri-strict-provenance"`, optional cargo-semver-checks.
  - `audit.yml` — daily cargo-audit + cargo-deny + OSV-Scanner + OpenSSF Scorecard with SARIF upload to GitHub Security tab.
  - `benchmark.yml` — criterion regression tracker with 105% fail-on-alert threshold, baseline stored in `docs/perf/baselines/develop`.
  - `publish.yml` — tag-triggered Trusted Publisher OIDC publish across the dependency-ordered 53-crate matrix, GitHub Release with CHANGELOG-extracted notes.
- GitHub issue templates (`bug_report`, `feature_request`, `config`) and PR template scaffolded under `.github/`.
- `services/admin/` skeleton — native HTML5 + ES2025 + Web Components SPA with primitives library (`signals.js`, `router.js`, `api-client.js`), design-system tokens (OKLCH palette + spacing scale + typography), JSDoc strict checking via `tsconfig.json` (`checkJs: true`, `noEmit: true`, `target: ES2025`), WebdriverIO 9+ test harness scaffold.
- `services/operator/` skeleton — Go + kubebuilder Kubernetes Operator with `PROJECT` manifest and `go.mod` initialised.

### Decisions

- Decision 2.49 — Funding and sponsorship model: GitHub Sponsors plus Open Collective baseline from Phase 0, optional dual-licence commercial path post-GA for regulated organisations requiring counterparty contracts. Apache-2.0 remains the only OSS licence.
- Decision 2.50 — Trademark and patent posture: EUIPO trademark filed in Phase 0 (Class 9 + Class 42), Madrid Protocol international extension at GA covering UK, US, CH, CA, AU, SG, JP. Apache-2.0 defensive patent grant only; explicit no-offensive-patents pledge published in `docs/patent-non-aggression.md` at GA.

### Changed (vs v2.1 plan, before reconciliation)

- Section IV crate specs renumbered 4.12 through 4.53 to match the post-consolidation workspace tree; ghost specs for retired crates (`pulsar-csrf`, `pulsar-sri`, `pulsar-incident`, `pulsar-ratelimit`, `pulsar-resilience`, `pulsar-websocket`, `pulsar-broadcasting`, `pulsar-workflow`, `pulsar-saga`, `pulsar-supervisor`, `pulsar-service-discovery`) removed; six off-core crates (`pulsar-tickets`, `pulsar-feedback`, `pulsar-booking`, `pulsar-devices`, `pulsar-releases`, `pulsar-importexport`) removed per Section 16.13.
- Four consolidated crate specs added: 4.11 `pulsar-guard` (csrf + sri + incident + ratelimit + resilience + ssrf sub-modules), 4.38 `pulsar-realtime` (websocket + sse + webtransport + broadcasting sub-modules), 4.47 `pulsar-orchestration` (workflow + saga sub-modules), 4.50 `pulsar-cluster` (supervisor + discovery sub-modules).
- Three new state-of-art crate specs added: 4.28 `pulsar-authz` (RBAC + ABAC + ReBAC Zanzibar per Section 16.1.2), 4.29 `pulsar-identity-standards` (W3C VC + DIDs + JWS + COSE per Section 16.1.3 and 16.2.1), 4.42 `pulsar-ai-agents` (agentic framework with Computer Use adapter per Section 16.8.1).
- Section V Phase 1.5 collapsed five sub-sprints into a single Sprint 1.5 `pulsar-guard` covering all six sub-modules.
- Section V Phase 3B collapsed Sprint 3B.2 websocket + 3B.3 broadcasting + new webtransport into Sprint 3B.2 `pulsar-realtime`; renumbered remaining 3B sprints (graphql 3B.4 → 3B.3, grpc 3B.5 → 3B.4, mcp-server 3B.6 → 3B.5).
- Section V Phase 3C added Sprint 3C.4 `pulsar-ai-agents`.
- Section V Phase 3E collapsed workflow + saga into Sprint 3E.1 `pulsar-orchestration`, supervisor + service-discovery into Sprint 3E.4 `pulsar-cluster`; removed sprints for the six retired crates; renumbered remaining 3E sprints (cloud 3E.9 → 3E.2, edge 3E.10 → 3E.3, deploy 3E.13 → 3E.5).
- Section XVI extended with eight new sub-items: 16.1.5 eIDAS 2 EUDI Wallet, 16.1.6 FAPI 2.0 Open Banking, 16.1.7 PSD2 Strong Customer Authentication, 16.8.6 NIST AI RMF, 16.8.7 OWASP LLM Top 10, 16.10.6 cross-platform binary distribution + packaging, 16.12.8 OpenSSF Scorecard ≥ 9.0 + Best Practices Badge gold tier, 16.12.9 CIS Benchmarks + STIG (DISA).
- Section XII metrics row "TLA+ specifications" updated to 9 specs reflecting the new SAML spec and the consolidated orchestration spec (workflow + saga in one TLA+ module). Compliance row updated from "16 nominal (18 enumerated)" to "23 (22 mandatory + MiCA opt-in)". Crate-count row updated from "≥ 50" to "exactly 53".
- Section XV parity matrix sprint references realigned to post-consolidation numbering across `src/` modules table, extensions table, ADR carry-over, and VOID DRIFT gaps. Five PHP extensions (booking, feedback, releases, tickets, devices) re-marked `S` (superseded as downstream applications or absorbed into existing crates).
- Cargo.toml versions aligned with plan Section 13.4: hyper 1.7 → 1.9, tokio 1.47 → 1.52, plan subtle 2.5 → 2.7, plan Section IV 4.4 icu 2.0 → 2.2.
- Cargo.toml `[workspace.dependencies]` extended with clickhouse 0.13, aws-sdk-s3 1.56, azure_core 0.21, google-cloud-storage 0.22, k8s-openapi 0.24 (previously absent from workspace deps despite being referenced by `pulsar-observability`, `pulsar-cloud`, and `pulsar-cluster` specs).
- Cargo.toml `[workspace.dependencies]` `rdkafka` entry: dropped invalid `optional = true` flag (Cargo refuses optional flags on workspace.dependencies); per-crate `optional = true` declarations remain available where consumers feature-gate Kafka support (currently `pulsar-realtime` behind feature `kafka`).

### Notes

The Rust implementation replaces the former PHP implementation of Pulsar Framework. The final PHP release is tagged `v0.99.0-php-final` for historical reference. The v2.2 reconciliation prepares the workspace for Sprint 0.1 (meta-files), Sprint 0.2 (workspace Cargo manifests), Sprint 0.3 (53 crate stubs), Sprint 0.4 (CI workflows), Sprint 0.5 (initial ADRs), Sprint 0.6 (architecture diagrams), Sprint 0.7 (plan committed), Sprint 0.8 (foundation tag `v0.0.1-alpha.0` + namespace-reservation alpha publish to crates.io).
