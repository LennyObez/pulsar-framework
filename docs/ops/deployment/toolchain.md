# Pulsar Framework — Pinned Toolchain

This document records the pinned toolchain for Pulsar Framework development, CI, and release builds. Every contributor and every CI runner must use the versions listed here to guarantee reproducible builds (per plan Section 14.6 SLSA Level 4 reproducibility requirement).

## Rust

| Component | Version | Source | Pin mechanism |
|-----------|---------|--------|---------------|
| `rustc` | **1.95.0** stable | rust-lang official release channel | `rust-toolchain.toml` at repository root |
| `cargo` | shipped with rustc 1.95.0 | (same) | (implicit via toolchain) |
| `rustfmt` | shipped with rustc 1.95.0 stable | (same) | (component declared in `rust-toolchain.toml`) |
| `clippy` | shipped with rustc 1.95.0 stable | (same) | (component) |
| `rust-analyzer` | shipped with rustc 1.95.0 stable | (same) | (component) |
| `rust-src` | shipped with rustc 1.95.0 stable | (same) | (component, required for `proptest` shrinking and Miri) |
| `llvm-tools-preview` | shipped with rustc 1.95.0 stable | (same) | (component, required by `cargo-llvm-cov`) |
| Edition | **2024** | (per-crate `edition.workspace = true`) | `[workspace.package].edition` in root `Cargo.toml` |

`rust-toolchain.toml` is authoritative. Running any `cargo` command in the workspace auto-installs the pinned toolchain via `rustup` if missing. The pin is locked per plan Decision 2.7 (Rust 1.95.0 stable, edition 2024); changing it requires an ADR.

## Linker

| Target | Linker | Source | Notes |
|--------|--------|--------|-------|
| `x86_64-unknown-linux-gnu` | **mold** (via `clang`) | <https://github.com/rui314/mold> | ~5x faster linking than `lld` at workspace scale, per plan Section VII |
| `aarch64-unknown-linux-gnu` | mold (via clang) | (same) | (same rationale) |
| `x86_64-apple-darwin` | system `ld` | (Xcode CLT) | mold not supported on macOS |
| `aarch64-apple-darwin` | system `ld` | (Xcode CLT) | (same) |
| `x86_64-pc-windows-msvc` | system `link.exe` | (MSVC tooling) | mold not yet supported on Windows |

Wired in `.cargo/config.toml` `[target.<triple>]` `linker` and `rustflags` keys. Install mold on Linux: `apt install mold` (Debian 12+/Ubuntu 24.04+) or `dnf install mold` (Fedora 40+). Verify with `mold --version` (expected ≥ 2.0).

## Compile cache

| Tool | Version | Source | Notes |
|------|---------|--------|-------|
| `sccache` | latest 0.x | <https://github.com/mozilla/sccache> | Shared cache across local development and CI; reduces incremental rebuild latency by 5-10x on small surface changes |

Activated in CI via the `RUSTC_WRAPPER=sccache` environment variable. Local opt-in by uncommenting the `rustc-wrapper = "sccache"` line in `.cargo/config.toml` `[build]` (intentionally commented by default to avoid forcing the dependency on every contributor).

## Workspace metadata

The root `Cargo.toml` declares the workspace at the repository root with 53 first-party crate members listed in `[workspace.members]`, organised in twelve layers (per plan Section 16.14 post-consolidation):

1. Meta — `pulsar-framework`
2. Kernel — `pulsar-kernel`
3. Core foundation — `pulsar-http`, `pulsar-engine`, `pulsar-orm`, `pulsar-audit`, `pulsar-auth`, `pulsar-compliance`, `pulsar-observability`, `pulsar-search`
4. Security controls — `pulsar-guard`
5. Application infrastructure — `pulsar-mail`, `pulsar-queue`, `pulsar-scheduler`, `pulsar-cache`, `pulsar-config`, `pulsar-storage`, `pulsar-form`, `pulsar-webhook`, `pulsar-idempotency`, `pulsar-notification`, `pulsar-pagination`, `pulsar-feature-flag`, `pulsar-i18n`, `pulsar-tenancy`
6. Data protection + Authz + Identity — `pulsar-dataprotection`, `pulsar-consent`, `pulsar-authz`, `pulsar-identity-standards`
7. Application extensions — `pulsar-cms`, `pulsar-forum`, `pulsar-payments`, `pulsar-console-api`
8. API paradigms — `pulsar-api`, `pulsar-graphql`, `pulsar-grpc`, `pulsar-mcp-server`, `pulsar-realtime`
9. AI surface — `pulsar-ai`, `pulsar-ai-governance`, `pulsar-vector-search`, `pulsar-ai-agents`
10. Reactive + Dev experience — `pulsar-live`, `pulsar-studio`, `pulsar-analytics`, `pulsar-accessibility`
11. Orchestration — `pulsar-orchestration`
12. Infrastructure adapters + CLI + Test — `pulsar-cloud`, `pulsar-edge`, `pulsar-cluster`, `pulsar-deploy`, `pulsar-cli`, `pulsar-test`

`[workspace.package]` declares the shared metadata (`version = "0.0.1-alpha.0"`, `edition = "2024"`, `rust-version = "1.95"`, `license = "Apache-2.0"`, etc.) that every crate inherits via `<key>.workspace = true`.

`[workspace.dependencies]` pins every external crate at a single version applied across the workspace. Per plan Section 13.4 External crate citation list, every entry has documented justification.

`resolver = "3"` (the latest Cargo resolver) is enabled at the workspace level for forward-compatible feature-unification semantics.

## Cargo configuration

`.cargo/config.toml` wires:

* `[build] jobs = -1` — use all available cores
* `[build] rustc-wrapper = "sccache"` — commented by default; activated in CI via env var
* `[target.<linux-triples>] linker = "clang"` + `link-arg=-fuse-ld=mold` + `force-frame-pointers=yes`
* `[target.<macos-triples>]` and `[target.<windows-triple>]` — system linker, force-frame-pointers
* `[target.wasm32-*]` — empty rustflags (WASM extension sandbox per plan Decision 2.19)
* `[alias]` — workflow shortcuts: `check-all`, `clippy-all`, `fmt-check`, `test-all`, `doc-test`, `cov`, `deny-check`
* `[net] git-fetch-with-cli = true` — avoid libgit2 quirks on large mirrors
* `[registries.crates-io] protocol = "sparse"` — 5-10x faster index pulls than the legacy Git protocol

## Format and lint policies

`rustfmt.toml` declares the active stable rustfmt policy:

* `edition = "2024"`
* `max_width = 100`
* `hard_tabs = false`
* `tab_spaces = 4`
* `reorder_imports = true`
* `reorder_modules = true`
* `use_field_init_shorthand = true`
* `use_try_shorthand = true`
* `newline_style = "Unix"`
* `match_arm_leading_pipes = "Never"`

Nightly-only rustfmt options (`imports_granularity`, `group_imports`, `wrap_comments`, `comment_width`, `format_strings`, `format_macro_matchers`, `normalize_comments`, `match_arm_blocks`, `force_multiline_blocks`, `fn_single_line`, `overflow_delimited_expr`) are documented as commented entries in `rustfmt.toml` for reference; they activate automatically on contributors who run `cargo +nightly fmt`. Stable `cargo fmt --check` ignores them silently.

`clippy.toml` declares:

* `cognitive-complexity-threshold = 15` (default 25)
* `too-many-arguments-threshold = 5` (default 7)
* `too-many-lines-threshold = 100`
* `enum-variant-size-threshold = 200`
* `msrv = "1.95.0"` (matches `rust-toolchain.toml`)
* `missing-docs-in-crate-items = true`

Workspace clippy is invoked with `cargo clippy --workspace --all-targets --all-features -- -D warnings` so every clippy warning is a hard error per plan Section VI quality gates.

## Dependency policy

`deny.toml` declares the cargo-deny policy:

* `[graph] targets` — six target triples (Linux glibc + musl x86_64/aarch64, macOS x86_64/aarch64, Windows MSVC, wasm32) per plan Section 14.6 SLSA 4 reproducibility scope
* `[advisories]` — RustSec advisory database, `yanked = "deny"`, zero tolerance on direct deps; transitive-only RUSTSEC advisories are explicitly listed in `ignore` with documented rationale and target sprint for elimination (Sprint 0.4 dep-hygiene formal exit gate)
* `[licenses]` — sixteen OSS-compatible licenses allowed (Apache-2.0 + LLVM-exception, MIT, MIT-0, BSD-2/3, 0BSD, BSL-1.0, ISC, MPL-2.0, Unicode-DFS-2016, Unicode-3.0, Zlib, CC0-1.0, CDLA-Permissive-2.0, OpenSSL via ring exception); copyleft (GPL family) deliberately excluded
* `[bans]` — explicit bans on `time < 0.3`, `chrono < 0.4.35`; `native-tls` denied except via documented `wrappers` (`hyper-tls`, `tokio-native-tls`, `reqwest`, `ldap3`) reflecting the transitive paths that current third-party SDK choices force
* `[sources]` — only the official crates.io registry is allowed; no git deps without explicit allowlist entry

## CI invocation

The Section VI quality-gate sequence (run at every sprint exit and in `.github/workflows/ci.yml`):

```bash
cargo fmt --all -- --check
cargo clippy --workspace --all-targets --all-features -- -D warnings
cargo check --workspace --all-targets --all-features
cargo nextest run --workspace --all-features
cargo test --workspace --doc
cargo llvm-cov --workspace --lcov --fail-under-lines 100        # critical-coverage tier crates
cargo llvm-cov --workspace --lcov --fail-under-lines 95          # other crates
cargo llvm-cov --workspace --branch --fail-under-branches 100    # critical-coverage tier
cargo llvm-cov --workspace --mcdc --fail-under-mcdc 100          # critical-coverage tier
cargo mutants --workspace --minimum-test-timeout 60              # ≥ 95% on critical tier
cargo fuzz run <target> -- -runs=10000000                        # parser sprints
cargo audit                                                       # zero advisories
cargo deny check advisories bans licenses sources
cargo machete                                                     # zero unused deps
cargo bench --workspace                                           # criterion vs baseline
cargo creusot                                                     # kernel sprints only
tlc -config spec/<name>.cfg spec/<name>.tla                      # kernel + Sprint 2.5 + 3B.2 + 3E.1
```

Critical-coverage tier crates: `pulsar-kernel`, `pulsar-guard`, `pulsar-compliance`, `pulsar-dataprotection` (per plan Section XII success metrics).

## Sprint 0.2 exit verification

This document closes Sprint 0.2 per plan Section V exit criteria:

* ✅ `cargo check --workspace` succeeds (verified locally on develop @ post-Sprint 0.1 baseline).
* ✅ `cargo deny check` succeeds (advisories ok, bans ok, licenses ok, sources ok) — transitive RUSTSEC advisories deferred to Sprint 0.4 dep-hygiene with documented `[advisories.ignore]` entries; `native-tls` ban handled via documented `wrappers` for the four transitive paths (hyper-tls, tokio-native-tls, reqwest, ldap3).
* ✅ `cargo fmt --check` succeeds (no nightly-only options in active config; nightly options commented for reference).

## Toolchain version review cadence

Per plan risk R-011 (Rust compiler regression) and R-016 (Rust ecosystem drift), the toolchain version is reviewed at every checkpoint exit (Section XI). Bumps to the minor version of `rustc` require an ADR; bumps to a new major version require a Section II decision update.

The current pin `1.95.0` was selected for plan Section II Decision 2.7. The next mandatory review is at Checkpoint 1 (Phase 1 kernel exit, tag `v0.1.0`).
