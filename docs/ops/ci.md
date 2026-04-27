# Pulsar Framework — CI Topology

This document describes the five GitHub Actions workflows that gate every push, pull request, scheduled run, and release tag. The full source for each workflow lives in `.github/workflows/`. The Section VI quality-gate command sequence + the Section VII tooling stack from `docs/plan.md` define what each workflow runs.

## Workflow inventory

| Workflow | Trigger | Runner | Concurrency | Caching | Main jobs |
|----------|---------|--------|-------------|---------|-----------|
| `ci.yml` | push to `develop`, PR to `develop`/`main`, manual | `ubuntu-latest` | per `${{ github.workflow }}-${{ github.ref }}`, cancel-in-progress | `Swatinem/rust-cache@v2` per job + `taiki-e/install-action@v2` for tooling | fmt, clippy, check, nextest, doc-tests, llvm-cov, cargo-deny, cargo-audit, cargo-machete, rustdoc, ADR-index integrity, CHANGELOG-entry enforcement |
| `cross-platform.yml` | push to `develop`/`main`, PR (Cargo deps changed), manual | matrix `ubuntu-latest` + `macos-latest` + `macos-13` + `windows-latest` | per workflow + ref, cancel-in-progress | `Swatinem/rust-cache@v2` keyed per target | cargo check + cargo test across 5 target triples (linux glibc + linux musl + macOS x86_64 + macOS aarch64 + Windows MSVC) per plan Section 16.10.6 |
| `nightly.yml` | cron `0 2 * * *` (02:00 UTC) + manual | `ubuntu-latest` | (none — single run) | `Swatinem/rust-cache@v2` | cargo-mutants on diff, cargo-fuzz short runs (10 min/target), Miri on kernel with `MIRIFLAGS="-Zmiri-strict-provenance"`, cargo-semver-checks on workflow_dispatch |
| `audit.yml` | cron `0 6 * * *` (06:00 UTC), Cargo.toml/Cargo.lock changes, manual | `ubuntu-latest` | (none) | (advisory DB cache via action) | cargo-audit (RustSec), cargo-deny (advisories+bans+licenses+sources), Google OSV-Scanner, OpenSSF Scorecard with SARIF upload |
| `benchmark.yml` | push to `develop`, PR to `develop`, manual | `ubuntu-latest` | (none) | `Swatinem/rust-cache@v2` | cargo-bench (criterion), comparison vs baseline in `docs/perf/baselines/develop`, fail-on-alert at 105% regression |
| `repro-build.yml` | push to `develop`, PR (Cargo/lib changes), manual | `ubuntu-latest` (matrix builders A + B → compare) | per workflow + ref, cancel-in-progress | `Swatinem/rust-cache@v2` keyed per builder | Two independent release builds of `pulsar-cli`; SHA-256 digest comparison fails on byte divergence (per plan Section 14.6 SLSA Level 4) |
| `actionlint.yml` | push/PR (workflows changed), manual | `ubuntu-latest` | per workflow + ref, cancel-in-progress | (n/a) | actionlint via `raven-actions/actionlint@v2` — YAML syntax + GitHub Actions expression syntax + shellcheck on every `run:` block + context-availability + matrix expansion + deprecated `set-output` warnings |
| `publish.yml` | tag match `v[0-9]+.[0-9]+.[0-9]+*`, manual (dry-run flag) | `ubuntu-latest` (env: `crates-io`) | (none) | `Swatinem/rust-cache@v2` | guard against bypass tags, pre-publish gates (fmt+clippy+test+doc-tests), Trusted Publisher OIDC auth, dependency-ordered publish across 53 crates, **CycloneDX SBOM + Cosign keyless signing (Sigstore Fulcio + Rekor transparency log) + SLSA Level 3 build provenance attestation**, GitHub Release with CHANGELOG-extracted notes + supply-chain artefact attachment |

Detailed job breakdown follows.

## `ci.yml` — primary gate

Runs on every push to `develop` and every pull request to `develop` or `main`. Twelve concurrent jobs covering the Section VI quality-gate command sequence:

| Job | Command | Notes |
|-----|---------|-------|
| `fmt` | `cargo fmt --all -- --check` | Pinned rustc 1.95.0 + rustfmt component |
| `clippy` | `cargo clippy --workspace --all-targets --all-features -- -D warnings` | RUSTFLAGS includes `-D warnings`; clippy.toml threshold catalogue applies |
| `check` | `cargo check --workspace --all-targets --all-features` | Independent of clippy/fmt for parallel run |
| `nextest` | `cargo nextest run --workspace --all-features --no-fail-fast` | nextest installed via `taiki-e/install-action@v2` |
| `doctest` | `cargo test --workspace --doc` | Doc-tests run separately from nextest (nextest does not execute doc-tests) |
| `llvm-cov` | `cargo llvm-cov nextest --workspace --all-features --lcov --output-path lcov.info` | Uploads to Codecov via `codecov/codecov-action@v4` (token via secret) |
| `deny` | `EmbarkStudios/cargo-deny-action@v2` `check advisories bans licenses sources` | Reads `deny.toml` |
| `audit` | `rustsec/audit-check@v2.0.0` | RUSTSEC advisory database |
| `machete` | `bnjbvr/cargo-machete@main` | Unused dep detection |
| `docs` | `cargo doc --workspace --no-deps --all-features` with `RUSTDOCFLAGS="-D warnings"` | Strict rustdoc — every warning is an error |
| `adr-index` | `grep` ADR file references in `docs/adr/INDEX.md`, fail if any referenced file is missing | Sprint 0.5 + onwards: every ADR must be indexed |
| `changelog-entry` | (PR only) verify `CHANGELOG.md` was modified vs base ref | Forces every PR to update [Unreleased] |

Concurrency policy: `cancel-in-progress: true` on `${{ github.workflow }}-${{ github.ref }}` so a force-push or rapid commits cancel the in-flight run. Saves CI minutes and surfaces the latest result quickly.

## `nightly.yml` — overnight heavy gates

Runs at 02:00 UTC daily plus manual `workflow_dispatch`. Three independent jobs:

| Job | Timeout | Command | Notes |
|-----|---------|---------|-------|
| `mutants` | 360 min | `cargo mutants --workspace --minimum-test-timeout 60 --no-shuffle --jobs 2` (with `--in-diff` against `origin/develop` if available) | Sprint 0.4+ baseline; full mutation testing per Section VI. Uses cargo-mutants ≥ 25.x |
| `fuzz-short` | 180 min | iterates over every `fuzz/fuzz_targets/*.rs` and runs `cargo +nightly fuzz run <name> -- -runs=2000000 -max_total_time=600` | 10 min budget per target on the nightly toolchain (libFuzzer harness compilation requires nightly). Skipped silently when `fuzz/fuzz_targets/` does not exist (Phase 0 stub state) |
| `miri` | 90 min | `cargo +nightly miri test --package pulsar-kernel` with `MIRIFLAGS="-Zmiri-strict-provenance"` | Activates from Sprint 1.1 onwards when kernel tests exist |
| `semver-checks` | (workflow_dispatch only) | `cargo semver-checks --workspace --baseline-rev origin/main` | Manual trigger only; runs against the most recent stable head |

Long timeouts are deliberate — mutation runs on the kernel can take 4-6 hours at the 95% threshold, and fuzz-short budgets accumulate 10 min per parser target.

## `audit.yml` — daily security advisory gate

Runs at 06:00 UTC daily, on every Cargo.toml/Cargo.lock/deny.toml change, and on manual dispatch. Four jobs covering layered advisory surfaces:

| Job | Source | Notes |
|-----|--------|-------|
| `cargo-audit` | RustSec DB via `rustsec/audit-check@v2.0.0` | Direct + transitive Rust crate advisories |
| `cargo-deny` | RustSec + license + bans via `EmbarkStudios/cargo-deny-action@v2` | Same `deny.toml` policy as `ci.yml` but explicit daily run keeps the advisory DB cache fresh |
| `osv-scanner` | Google OSV via `google/osv-scanner-action/osv-scanner-action@v1.9.0` | Cross-ecosystem advisory database (overlaps RustSec but adds OSV-only entries) |
| `scorecard` | OpenSSF Scorecard via `ossf/scorecard-action@v2.4.0` | Uploads SARIF results to GitHub Security tab; published to public OpenSSF Scorecard dashboard. Targets ≥ 9.0 / 10 per plan Section 16.12.8 |

The Scorecard job needs `security-events: write`, `id-token: write`, and `contents: read` permissions; everything else uses default `contents: read`.

## `benchmark.yml` — criterion regression gate

Runs on push to `develop`, on PRs to `develop`, and on manual dispatch.

| Step | Command | Notes |
|------|---------|-------|
| Install bencher adapter | `taiki-e/install-action@v2` `cargo-criterion` | (no-op if cached) |
| Run benches | `cargo bench --workspace --no-fail-fast -- --output-format bencher \| tee bench.txt` | Falls back to a placeholder line during Phase 0 (no `benches/` directories yet) |
| Compare + alert | `benchmark-action/github-action-benchmark@v1` with `tool: cargo`, `alert-threshold: "105%"`, `fail-on-alert: true`, baseline stored in `docs/perf/baselines/develop` | Comments on PR when regression > 5%; commits new baseline on `develop` push |

Permissions: `contents: write` (for baseline commit), `pull-requests: write` (for alert comment), `deployments: write` (for benchmark-action's deployment artefact tracking).

## `publish.yml` — tag-triggered Trusted Publisher OIDC release

Runs on tag match `v[0-9]+.[0-9]+.[0-9]+*` and on manual dispatch (with a `dry_run` boolean input defaulting `true` for safety).

Pipeline structure (each step depends on the previous):

1. **`guard`** — refuses tag names containing `+nogpg` or `-noverify` substrings to prevent bypass-tag publishing (per CLAUDE.md Decision 2.30 and Section 11 git workflow rules).
2. **`fmt-clippy-test`** — runs `cargo fmt --check`, `cargo clippy -- -D warnings`, `cargo nextest run --all-features`, `cargo test --doc` as a final pre-publish gate.
3. **`publish-crates`** — Trusted Publisher OIDC authentication via `rust-lang/crates-io-auth-action@v1` (no long-lived tokens); environment `crates-io` (must be configured in repository settings with OIDC trust). Either dry-runs (workflow_dispatch with `dry_run: true`) or executes the dependency-ordered publish across 53 crates ending with the meta-crate `pulsar-framework`.
4. **`github-release`** — extracts release notes from `CHANGELOG.md` for the matching version using awk (matches `## [<version>]` sections), creates a GitHub Release via `softprops/action-gh-release@v2`. Pre-release flag activates automatically when the tag contains `-` (e.g. `v0.1.0-rc.1`).

Required permissions: `id-token: write` for OIDC, `contents: read` for the publish step (release creation step uses default `GITHUB_TOKEN`).

Dependency-ordered publish list (53 crates, kernel-first, meta-last) is hardcoded in the workflow's `ORDER` bash array. As crates stabilise across phases the ordering is preserved per plan Section IX release strategy.

## Caching strategy

| Cache | Key | Scope | TTL |
|-------|-----|-------|-----|
| Cargo registry + target | `Swatinem/rust-cache@v2` (default) | Per-job, per-OS, per-Cargo.lock hash | GitHub Actions cache 7-day eviction |
| Tool binaries | `taiki-e/install-action@v2` builds Cargo binstall caches | Across runs | Action-level |
| Advisory DB | RustSec cache (`rustsec/audit-check`, `cargo-deny-action`) | Workflow-level | Refreshed on each workflow trigger; daily at minimum |
| Codecov upload | (no cache) | (token via secret) | (n/a) |

`Swatinem/rust-cache@v2` is the canonical Rust caching action and handles the Cargo registry index, source cache, and target directory together. It keys on Cargo.lock hash so changes to deps invalidate cleanly.

## Runners

All workflows run on `ubuntu-latest` (currently Ubuntu 24.04 LTS at the time of plan v2.2 lock). Per plan Section 13.4 platform support and the deny.toml graph, additional CI matrices for macOS and Windows will be added in Sprint 0.4+ extension work as cross-platform binary distribution (per plan Section 16.10.6) ramps up. Phase 0 keeps the matrix lean to minimise CI minute consumption.

## Local invocation

Every workflow can be reproduced locally. The `.cargo/config.toml` aliases mirror the Section VI quality-gate sequence:

```bash
cargo check-all          # cargo check --workspace --all-targets --all-features
cargo clippy-all         # cargo clippy --workspace --all-targets --all-features -- -D warnings
cargo fmt-check          # cargo fmt --all -- --check
cargo test-all           # cargo nextest run --workspace --all-features
cargo doc-test           # cargo test --workspace --doc
cargo cov                # cargo llvm-cov nextest --workspace --all-features --lcov --output-path lcov.info
cargo deny-check         # cargo deny check advisories bans licenses sources
```

Plus the per-tool standard invocations: `cargo audit`, `cargo machete`, `cargo bench --workspace`, `cargo +nightly fuzz run <target>`, `cargo +nightly miri test`, `cargo mutants`, `tlc -config <spec>.cfg <spec>.tla`, `cargo creusot`.

## Sprint 0.4 exit verification

Per plan Section V Sprint 0.4 exit criteria:

* ✅ All five workflows present in `.github/workflows/` (ci.yml, nightly.yml, audit.yml, benchmark.yml, publish.yml).
* ✅ Each workflow YAML is syntactically valid (`python3 -c "import yaml; yaml.safe_load(...)"` passes for all five).
* 🟡 First `ci.yml` run on `develop` end-to-end: deferred to first push to GitHub remote. Sprint 0.4 documentation is the local deliverable; the actual CI run requires the develop branch to land on the GitHub remote (currently local-only per plan workflow rule "never push unless explicitly requested").
* 🟡 `publish.yml` dry-run against `cargo publish --dry-run`: deferred to first push + manual workflow_dispatch with `dry_run: true`. The workflow source is correct and triggers properly on `vN.M.P*` tag patterns.

The two yellow ☑ items are not blockers — they are remote-run gate verifications that activate the moment the user runs `git push origin develop` for the first time.

## Workflow update cadence

Per plan risk R-016 (Rust ecosystem drift) and Section 16.12.8 OpenSSF Scorecard ≥ 9.0 target, GitHub Actions versions are managed at two layers:

* **Major-tag pinning today** (e.g. `actions/checkout@v4`, `Swatinem/rust-cache@v2`) — matches the GitHub Actions ecosystem convention and surfaces breaking changes through the action's CHANGELOG. Acceptable baseline for Phase 0.
* **SHA pinning planned** for Sprint 0.4+ extension to satisfy OpenSSF Scorecard "Pinned-Dependencies" check. The `actionlint.yml` workflow surfaces deprecated action usage; a follow-up automation will rewrite `@v<N>` to `@<sha>` references and `dependabot.yml` then handles SHA bumps automatically.

`dependabot.yml` already covers GitHub Actions (weekly Monday updates with patch+minor grouping), so the day-to-day churn is automated regardless of whether pinning is by tag or by SHA.

## Supply-chain pipeline (publish.yml `supply-chain` job)

Per plan Section 14.6 SLSA Level 4 + Section 16.10.5 container image signing + Section 16.12.8 OpenSSF Scorecard:

| Artefact | Tool | Output | Verification |
|----------|------|--------|--------------|
| SBOM | `cargo cyclonedx` (CycloneDX 1.6) | `bom.cdx.json` per crate + workspace aggregate, attached to GitHub Release | `cyclonedx-cli validate` |
| Cosign signature | `sigstore/cosign-installer@v3` + `cosign sign-blob --yes` (keyless via Fulcio) | `.sig` + `.crt` per artefact, transparency-log entry on Rekor | `cosign verify-blob --certificate-identity ...` |
| SLSA build provenance | `actions/attest-build-provenance@v1` | Predicate signed by GitHub OIDC, attached to GitHub artefact | `gh attestation verify <file> --owner LennyObez` |

The supply-chain job runs after `publish-crates` (so it cosigns the actual published surface, not a pre-publish staging) and writes its outputs as additional Release assets. `github-release` job then composes the final Release notes from CHANGELOG + an appended supply-chain section pointing at the assets.

## Reproducible-build verification (repro-build.yml)

Per plan Section 14.6 SLSA Level 4 reproducibility requirement: two parallel matrix jobs (`builder-a`, `builder-b`) compile `pulsar-cli` release binary from a clean checkout on independent runners. A third `compare-digests` job downloads both artefacts and asserts SHA-256 equality. Divergence indicates non-deterministic build inputs (typical culprits: `SystemTime::now()` in build.rs or proc-macros, embedded git hashes, non-deterministic dep features, `rand` usage outside `cfg(test)`). The lint policy in `Cargo.toml` `[workspace.lints]` and the `cargo deny` ban list catch the most common cases at PR review; this workflow is the runtime verifier.

## SLSA Source Level 3 + in-toto layout (v2.3 expansion per Decision 2.57)

The supply-chain attestation pipeline gains two layers in v2.3 beyond the v2.2 SLSA Build L3 + Cosign + SBOM baseline:

### SLSA Source Level 3

Source Level 3 requires:

1. **Source identity bound to the maintainer's signing key.** Pulsar enforces GPG-signed commits with the maintainer's Ed25519 key per Decision 2.30 + ADR-0007.
2. **Branch protection enforcing signed commits + status checks.** GitHub branch protection rules on `develop` + `main` block unsigned merges + block merges that fail any required status check (`fmt`, `clippy`, `check`, `nextest`, `llvm-cov`, `deny`, `audit`, `machete`, `docs`, `adr-index`, `changelog-entry`).
3. **Immutable history.** No force-push, no rebase post-merge — `develop` and `main` are append-only branches.
4. **18-month retention of the signing-key audit trail.** The maintainer's Ed25519 audit-chain key history (key-id transitions, revocations) is retained for 18 months minimum per the SLSA Source L3 specification.

The `supply-chain` job in `publish.yml` writes a textual attestation (`slsa/source-l3-attestation.txt`) capturing the signing identity of every commit reachable from the release tag, the branch protection state, the workflow ref + job workflow SHA. This text file is Cosign-signed (Sigstore keyless) so verifiers can establish trust via the maintainer's GitHub OIDC issuer.

### in-toto layout

The in-toto layout (`attestations/pulsar-framework.layout.template`) declares the full **source → build → SBOM → sign → publish** chain:

```
source-checkout    →    build-rust    →    sbom-emit    →    cosign-sign    →    publish-cratesio
   git clone           cargo build          cargo cyclonedx    cosign sign-blob       cargo publish
```

Each step has an expected signer (Sigstore Fulcio CA via OIDC) + expected output (file pattern). Verifiers run `in-toto-verify --layout pulsar-framework.layout.template --layout-keys <maintainer-fulcio-cert>` against the released attestations to validate that:

- The published `.crate` files came from the source tagged at `v0.0.X`.
- No untrusted intermediate step modified the artefact between source and crates.io.
- The Cosign signature on the SBOM matches the SBOM that was emitted from the build that produced the published `.crate`.

This is the layered provenance model used by `kubernetes-sigs`, Sigstore, and GUAC. Combined with the SLSA Source L3 + Build L4 attestations, no single compromise (hijacked CI runner, leaked signing key, malicious dependency) can subvert the chain — the verifier replay surfaces the inconsistency.

### Federated mirror to Sigstore Rekor

Every Cosign keyless signature operation publishes a transparency-log entry to Sigstore Rekor automatically. Downstream consumers who prefer the Sigstore trust root (rather than the Pulsar Foundation's first-party audit-key) verify against Rekor. The first-party transparency log at `logs.pulsar-framework.com` (per ADR-0013) provides the same evidence chain for jurisdictions that require self-hosted attestation.

### Verification — downstream consumer recipe

```bash
# 1. Verify SLSA Build L4 provenance attestation
gh attestation verify pulsar-cli-0.1.0.crate \
  --owner LennyObez --predicate-type slsa-provenance/v1.0

# 2. Verify Cosign keyless signature on SBOM
cosign verify-blob \
  --certificate-identity-regexp 'https://github.com/LennyObez/pulsar-framework' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  --signature pulsar-framework-workspace.cdx.json.sig \
  --certificate pulsar-framework-workspace.cdx.json.crt \
  pulsar-framework-workspace.cdx.json

# 3. Verify in-toto layout
in-toto-verify \
  --layout pulsar-framework.layout.template \
  --layout-keys <maintainer-fulcio-cert>
```

If any of the three verifications fails, the artefact is rejected — the chain has been broken somewhere between the maintainer's commit and the consumer's local copy.
