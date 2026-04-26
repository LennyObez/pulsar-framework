# Pulsar Framework — CI Topology

This document describes the five GitHub Actions workflows that gate every push, pull request, scheduled run, and release tag. The full source for each workflow lives in `.github/workflows/`. The Section VI quality-gate command sequence + the Section VII tooling stack from `docs/plan.md` define what each workflow runs.

## Workflow inventory

| Workflow | Trigger | Runner | Concurrency | Caching | Main jobs |
|----------|---------|--------|-------------|---------|-----------|
| `ci.yml` | push to `develop`, PR to `develop`/`main`, manual | `ubuntu-latest` | per `${{ github.workflow }}-${{ github.ref }}`, cancel-in-progress | `Swatinem/rust-cache@v2` per job + `taiki-e/install-action@v2` for tooling | fmt, clippy, check, nextest, doc-tests, llvm-cov, cargo-deny, cargo-audit, cargo-machete, rustdoc, ADR-index integrity, CHANGELOG-entry enforcement |
| `nightly.yml` | cron `0 2 * * *` (02:00 UTC) + manual | `ubuntu-latest` | (none — single run) | `Swatinem/rust-cache@v2` | cargo-mutants on diff, cargo-fuzz short runs (10 min/target), Miri on kernel with `MIRIFLAGS="-Zmiri-strict-provenance"`, cargo-semver-checks on workflow_dispatch |
| `audit.yml` | cron `0 6 * * *` (06:00 UTC), Cargo.toml/Cargo.lock changes, manual | `ubuntu-latest` | (none) | (advisory DB cache via action) | cargo-audit (RustSec), cargo-deny (advisories+bans+licenses+sources), Google OSV-Scanner, OpenSSF Scorecard with SARIF upload |
| `benchmark.yml` | push to `develop`, PR to `develop`, manual | `ubuntu-latest` | (none) | `Swatinem/rust-cache@v2` | cargo-bench (criterion), comparison vs baseline in `docs/perf/baselines/develop`, fail-on-alert at 105% regression |
| `publish.yml` | tag match `v[0-9]+.[0-9]+.[0-9]+*`, manual (dry-run flag) | `ubuntu-latest` (env: `crates-io`) | (none) | `Swatinem/rust-cache@v2` | guard against bypass tags, pre-publish gates (fmt+clippy+test+doc-tests), Trusted Publisher OIDC auth, dependency-ordered publish across 53 crates, GitHub Release with CHANGELOG-extracted notes |

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

Per plan risk R-016 (Rust ecosystem drift), workflow action versions are reviewed at every checkpoint exit. Action pins are intentionally floating (`@v4`, `@v2`, etc.) on major version markers — this matches the GitHub Actions ecosystem convention and surfaces breaking changes through the GitHub change log. Strict SHA pinning is a Sprint 0.4+ extension consideration once the supply-chain attestation pipeline (per plan Section 16.10.5) is in place.
