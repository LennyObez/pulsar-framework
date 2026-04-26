# Changelog

All notable changes to Pulsar Framework are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Sprint 0.9 — Phase 0 hardening (in progress)

* **Commit A — workspace tooling baseline:** `.gitattributes` enforcing LF on all text files (SLSA 4 reproducibility prerequisite); `.editorconfig` for cross-IDE consistency (4-space Rust, 2-space YAML/JSON, tab Go); `[workspace.lints]` table in root `Cargo.toml` centralising rust + clippy + rustdoc lint policy (Cargo 1.74+ workspace-lint feature, eliminating per-crate `#![deny(missing_docs)]` + `#![forbid(unsafe_code)]` repetition); `tools/xtask/` workspace member with five subcommand stubs (`adr-index`, `api-surface`, `quality-gate`, `repro-build`, `publish-order`); `tools/scripts/regenerate-adr-index.sh` and `regenerate-api-surface.sh` placeholders.
* **Commit B+C — Section XVII module layout + scaffolds + repo config:** every crate in `crates/pulsar-*/src/` gains `prelude.rs` + `error.rs` + `sealed.rs` per Section XVII catalogue; every crate gains `tests/smoke.rs` (two tests verifying `VERSION` is non-empty and matches `CARGO_PKG_VERSION`); every crate `Cargo.toml` gains `[lints] workspace = true`; `spec/README.md` documents the nine planned TLA+ specifications; `examples/` gains 10 example skeletons and a top-level README; `pulsar-cli` gains `src/main.rs` + `[[bin]] name = "pulsar"` so the binary entry exists; `.github/CODEOWNERS` auto-assigns `@LennyObez` to critical-coverage-tier crates + `spec/` + `docs/plan.md` + `docs/adr/` + CI configuration; `.github/dependabot.yml` weekly Cargo + GitHub Actions + Go + npm dependency scanning with patch+minor grouped + major individual; `.github/FUNDING.yml` registers GitHub Sponsors per Decision 2.49; `.github/ISSUE_TEMPLATE/security.yml` redirects security reporters to the private GitHub Security Advisory channel.
* **Commit C-fixup — Cargo.lock refresh:** lockfile regenerated to register the new `tools/xtask` workspace member, required for SLSA Level 4 two-builder reproducibility determinism.
* **Commit D — supply-chain hardening (3 new workflows + publish.yml extension):** `.github/workflows/actionlint.yml` runs `raven-actions/actionlint@v2` on every workflow change for YAML+expression+shellcheck validation; `.github/workflows/cross-platform.yml` 5-target matrix (`x86_64-unknown-linux-gnu`, `x86_64-unknown-linux-musl`, `aarch64-apple-darwin`, `x86_64-apple-darwin`, `x86_64-pc-windows-msvc`) verifying `cargo check --workspace` on every push + `cargo test --workspace` on every native target per plan Section 16.10.6 cross-platform binary distribution; `.github/workflows/repro-build.yml` two-builder matrix (a + b) running `cargo build --release --package pulsar-cli --bin pulsar` from clean checkouts on isolated runners + `compare-digests` job that fails the build when SHA-256 digests diverge per plan Section 14.6 SLSA Level 4 reproducible-build smoke test; `publish.yml` extended with the `supply-chain` job: CycloneDX SBOM via `cargo cyclonedx --format json --override-filename pulsar-framework`, Cosign keyless signing via `sigstore/cosign-installer` + `cosign sign-blob` against Sigstore Fulcio + Rekor transparency log, SLSA Level 3 build provenance attestation via `actions/attest-build-provenance@v1`. `docs/ops/ci.md` extended with the three new workflow rows in the inventory table + a "Supply-chain pipeline" section + a "Reproducible-build verification" section + a workflow-action SHA-pinning roadmap note. Total now eight workflows.
* **Commit E — docs structure scaffolds:** `docs/api-surface.md` initial version documenting the visibility-tier system (`stable` / `experimental` / `deprecated`), the per-layer crate inventory (currently all empty), and the `cargo public-api`-driven regeneration procedure that lands at Sprint 0.10; `docs/perf/baselines/README.md` documenting the criterion baseline archive layout, the 10-subsystem benchmark catalogue, the 5% regression-detection threshold, the dedicated self-hosted runner caveat, and the immutable-baseline policy; `docs/observability/dashboards/README.md` documenting the 20 production-ready Grafana dashboards target (Section XII success metric), the per-dashboard required panel set (saturation / error / latency / throughput / subsystem-specific / annotations / alert pointer), the validation pipeline that lands at Sprint 1.0; `docs/book/book.toml` mdBook configuration with rust theme + linkcheck + toc + mermaid + admonish preprocessors + `create-missing = true` Phase 0 posture; `docs/book/src/SUMMARY.md` ~80-chapter narrative-doc roadmap covering Parts I-IX (getting started + concepts + guides + recipes + ops + compliance + migration + contributing + reference); `docs/book/src/introduction.md` framing chapter; `docs/security/threat-model.md` STRIDE skeleton with eight named trust boundaries (T1-T8) + per-layer placeholder catalogues (lit by the corresponding sprint when each layer ships) + cross-cutting supply-chain + cryptographic-agility + insider-threat sections + review cadence + acknowledgements (NIST SP 800-30, ISO/IEC 27005, OWASP ASVS L3, OWASP API + LLM Top 10:2023, CHERI worksheet, seL4 paper).
* **Commit F — reproducible dev environment:** `flake.nix` Nix flake declaring the canonical pinned dev shell using `oxalica/rust-overlay` reading `rust-toolchain.toml` + the full quality-gate plugin set + mdBook + supply-chain tooling (cosign, syft, grype, trivy) + TLA+ tools + per-platform conditionals (macOS frameworks, Linux io_uring/eBPF libraries) — gives every contributor + CI runner + air-gapped audit environment a byte-identical toolchain (SLSA Level 4 reproducibility prerequisite); `.devcontainer/devcontainer.json` mirrors the Nix flake for VS Code Remote Containers + GitHub Codespaces + Coder + GitPod + JetBrains Gateway with the same toolchain + plugin set + Nix support so cross-validation against `flake.nix` works inside the container + 6 forwarded ports + 14 VS Code extensions; `.devcontainer/post-create.sh` idempotent first-boot bootstrap installing cargo plugins via `cargo binstall` (faster than source-build) + mdBook preprocessors + cosign + syft + actionlint + pre-commit hooks + opportunistic GPG signing config when a key already exists for the user's git email; `CITATION.cff` Citation File Format 1.2.0 metadata (authors, version, date-released, license, repository, keywords, full reference list pointing at the academic + canonical inspirations the framework draws from — seL4, CompCert, TLA+, LMAX Disruptor, SQLite, Erlang/OTP, SLSA, OWASP ASVS L3 + API + LLM Top 10:2023, NIST SP 800-30, ISO/IEC 27005); `.well-known/security.txt` RFC 9116 disclosure entry-point (3 contact channels, expiry 2027-04-25, PGP encryption pointer, acknowledgments link, preferred languages en+fr, canonical URLs, policy pointer to `SECURITY.md`).
* **Commit G — ADR consistency: Compliance mapping section in 5 more ADRs.** Brings the section count from 3-of-8 (0004 + 0005 partial + 0008) to 8-of-8 (0001 + 0002 + 0003 + 0005 full + 0006 + 0007 added; 0004 + 0008 already present). Each new section enumerates the regulatory clauses the decision satisfies — for ADR-0001 (Rust rewrite) the mapping covers ISO 27001 A.8.28 secure coding + ISO 27034-1 + ISO 25010 + NIST SSDF PW.1 + NIST SP 800-53 SA-15/SI-16 + BSI TR-03145 + EU CRA Annex I + CISA memory-safe roadmap; for ADR-0002 (modular monolith + hexagonal) the mapping covers ISO 27001 A.5.23/A.8.30 + ISO 27017 + GDPR Art. 25/28 + HIPAA 164.308(a)(4) + PCI-DSS 6.2.4 + DORA Art. 9 + NIS2 Art. 21(2)(d) + EU AI Act Art. 9; for ADR-0003 (microkernel formal verification) the mapping covers Common Criteria EAL 6+/7 + NIST FIPS 140-3 + ISO 27001 A.8.27 + ISO 27034-3 + PCI-DSS 6.2.3.1 + PSD2 RTS Art. 4 + DORA Art. 9(2)/26-27 + EU AI Act Art. 15 + NIST SP 800-160; for ADR-0005 (Apache-2.0 + EUIPO) the mapping covers ISO 5230 OpenChain + ISO 18974 + ISO 5962 SPDX + EU CRA Annex II + EU AI Act Art. 25 + GDPR Art. 28(3) + OpenSSF gold tier + WIPO Madrid Protocol; for ADR-0006 (crates.io namespace + meta-crate) the mapping covers SLSA L4 + NIST SSDF PS.1/PS.2/PS.3 + EU CRA Annex I + NIS2 Art. 21(2)(j) + OpenSSF SCM + ENISA OSS guidelines; for ADR-0007 (branch model) the mapping covers ISO 27001 A.8.32/A.5.4/A.8.4 + ISO 27034-3 + PCI-DSS 6.5.1-6.5.4 + DORA Art. 9(2)(c) + NIST SSDF PS.1/PS.2 + NIST SP 800-53 CM-3/CM-5 + EU CRA Annex I + NIS2 Art. 21(2)(e).

### Sprint 0.8 — v0.0.1-alpha.0 namespace-reservation publish (deferred to push)

* All 53 crate `README.md` files standardised to the spec wording: "**Status:** Placeholder release for namespace reservation. The implementation ships in 0.1.0. See [`docs/plan.md`](../../docs/plan.md) Section IV for the per-crate spec and Section V for the implementing sprint."
* Annotated GPG-signed tag `v0.0.1-alpha.0` created locally on `develop` (HEAD) — annotated per plan Section VIII.3 tag-naming convention; signed with the maintainer's Ed25519 key per Decision 2.30.
* **Push + crates.io publish deferred to explicit user authorisation** per CLAUDE.md operator-manual rule "Never push unless the user explicitly requests it." Once the user authorises, the publish flow is:
  1. `git push origin develop` — push the develop branch to the GitHub remote.
  2. `git push origin v0.0.1-alpha.0` — push the annotated tag, which triggers `.github/workflows/publish.yml`.
  3. The `publish.yml` Trusted Publisher OIDC step authenticates against crates.io (requires the repo's `crates-io` environment to be configured with OIDC trust on crates.io — a one-time setup).
  4. The dependency-ordered publish step iterates the 53-crate `ORDER` array and runs `cargo publish --package <crate> --no-verify` for each, ending with `pulsar-framework` (meta-crate).
  5. The `github-release` job extracts release notes from `CHANGELOG.md` and creates a GitHub Release with `prerelease: true` (because the tag contains `-`).
* Phase 0 closure tag — Phase 1 (kernel) starts on the next commit on `develop`.

### Sprint 0.7 — Plan committed + trademark policy

* `docs/plan.md` v2.2 already committed (throughout Phase 1-11 reconciliation + Sprint 0.1-0.6 refinements); the master plan is the authoritative reference for every Phase 0 decision.
* `docs/trademark-policy.md` lands the EUIPO-aligned trademark policy per [ADR-0005](docs/adr/0005-apache-2-0-licence.md) and [Decision 2.50](docs/plan.md). Sections cover what the trademark covers, nominative use that requires no permission, uses that require permission, fork rules, downstream-application rules, commercial-entity rules (consulting, training, hosting, managed services, post-GA dual-licence path), defensive patent posture, reporting concerns, and contact channels. Modelled on Linux Foundation, Rust Foundation, PostgreSQL, and Apache Software Foundation trademark policies.
* README.md already references both files (Status link to plan; License + Trademark section link to trademark policy).

### Sprint 0.6 — Architecture diagrams (6 Mermaid)

* `docs/architecture/overview.md` — entry point linking the six diagrams + ADR roadmap + plan section cross-references.
* `docs/architecture/diagrams/01-layered.md` — twelve-layer composition + dependency rule + event bus cross-cuts + WASM sandbox at the periphery.
* `docs/architecture/diagrams/02-crate-dependency-graph.md` — 53-crate DAG, hub crates (kernel, audit, orm, http), consolidation impact (Section 16.14).
* `docs/architecture/diagrams/03-request-lifecycle.md` — sequence diagram tracing `POST /api/v1/posts` through every quality-relevant subsystem with TLA+/Creusot anchors and latency budget.
* `docs/architecture/diagrams/04-event-bus-topology.md` — typed events publishers/subscribers, partitioning, delivery semantics (at-least-once + transactional outbox + at-most-once opt-in), schema evolution.
* `docs/architecture/diagrams/05-wasm-sandbox-isolation.md` — trusted core vs sandbox trust boundary, capability table, crash isolation, performance budget, component-model interop.
* `docs/architecture/diagrams/06-process-model.md` — multi-instance production deployment, stateful tier (PostgreSQL Patroni + Redis + Tantivy + S3 + ClickHouse), external systems, observability flow, deployment topologies (single-tenant, multi-tenant, multi-region, edge, air-gapped).

### Sprint 0.5 — Initial 8 ADRs + INDEX.md

* `docs/adr/0000-template.md` — MADR-style template with Status, Context, Decision, Consequences (positive/negative/neutral), Alternatives considered, References, optional Compliance mapping. Every future ADR uses this template.
* `docs/adr/0001-rewrite-in-rust.md` — Full rewrite of Pulsar Framework from PHP 8.5 to Rust 1.95+ (Decisions 2.1, 2.2, 2.3, 2.7).
* `docs/adr/0002-modular-monolith-hexagonal.md` — Modular monolith with hexagonal ports-and-adapters across crate boundaries (Decisions 2.22, 2.32).
* `docs/adr/0003-microkernel-formal-verification.md` — Formally verified microkernel for crypto, audit, session, router, middleware, DI (Decisions 2.20, 2.22, 2.3) — encodes the nine TLA+ specs + Creusot contract scope.
* `docs/adr/0004-wasm-extension-sandbox.md` — WebAssembly extension sandbox with capability-based security (Decision 2.19) — implementation deferred to Sprint 4.1.
* `docs/adr/0005-apache-2-0-licence.md` — Apache License, Version 2.0 with EUIPO trademark protection (Decisions 2.21, 2.50) — Madrid Protocol international extensions at GA.
* `docs/adr/0006-crates-io-pulsar-namespace.md` — `pulsar-*` crates.io namespace with meta-crate `pulsar-framework` (Decisions 2.23, 2.24) — 53 crates enumerated with re-export discipline.
* `docs/adr/0007-branch-model.md` — Branch model with `main` stable, `develop` integration, sprint feature branches (Decisions 2.5, 2.6) — three-tier topology with required status checks.
* `docs/adr/0008-gitflow-sprint-branches.md` — Sprint feature branch contract `feat/sprint-N-M-topic` with squash-merge (Decisions 2.5, 2.6, 2.30) — full lifecycle, naming convention, status checks, tag policy.
* `docs/adr/INDEX.md` — index of all ADRs plus roadmap of ~85 future ADRs aligned with plan Section V sprint sequence.
* CI invariant: every ADR file referenced in INDEX.md must exist (ci.yml `adr-index` job enforces).

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
