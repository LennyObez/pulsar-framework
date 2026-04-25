# Contributing to Pulsar Framework

Pulsar Framework targets regulated, mission-critical domains. Every contribution is held to the same bar as the core code: formally verified where possible, exhaustively tested, and reviewed with adversarial intent. This document describes the workflow, quality gates, and conventions contributors must follow.

## Prerequisites

- **Rust 1.95.0 stable** (pinned via `rust-toolchain.toml`)
- **Linux or macOS** as primary development host (Windows via WSL2 Ubuntu is supported)
- **Git 2.40+** with GPG signing configured
- **cargo-binstall** for fast installation of auxiliary tooling
- **Docker** (for integration tests against PostgreSQL, Redis, Memcached)

Bootstrap:

```bash
git clone https://github.com/LennyObez/pulsar-framework.git
cd pulsar-framework
cargo binstall --no-confirm cargo-watch cargo-nextest cargo-llvm-cov cargo-mutants cargo-audit cargo-deny cargo-fuzz bacon sccache
rustup component add clippy rustfmt rust-analyzer rust-src llvm-tools-preview
```

## Branch model

```
main        ─ stable, protected, Rust 1.0+ release branches
develop     ─ active integration branch
feat/*      ─ feature branches off develop (e.g. feat/sprint-1-1-crypto)
fix/*       ─ bug-fix branches off develop or main
security/*  ─ security-sensitive branches (private when appropriate)
docs/*      ─ documentation-only changes
chore/*     ─ tooling, build, dependency updates
```

- Commits must be **GPG-signed**
- Commit messages follow **Conventional Commits**: `<type>(<scope>): <imperative summary>`
  - Types: `feat`, `fix`, `docs`, `perf`, `refactor`, `test`, `ci`, `build`, `chore`, `security`
  - Scopes: `kernel`, `http`, `engine`, `orm`, `audit`, `auth`, `compliance`, `obs`, `cms`, `forum`, `payments`, `console`, `cli`, `test`, `ci`, `docs`, `arch`
- No `Co-Authored-By` trailers
- Pull requests target `develop`; the `develop → main` merge is reserved for release cycles

## Quality gates (non-negotiable)

Before opening a pull request, run the full gate suite locally. Every item must pass.

```bash
cargo fmt --check
cargo clippy --all-targets --all-features -- -D warnings
cargo check --all-targets --all-features
cargo nextest run --all-features
cargo llvm-cov --all-features --lcov --output-path coverage.lcov
cargo mutants --workspace --minimum-test-efficacy 95
cargo audit --deny warnings
cargo deny check
cargo machete
```

Per-module requirements:

- **Line, branch, condition coverage**: 100%
- **Mutation kill rate**: ≥ 95%
- **Fuzz targets** (parser modules only): `cargo fuzz run <target> -- -max_total_time=3600` must terminate with zero crashes
- **Benchmark regression** (`criterion`): zero regression versus the committed baseline in `docs/perf/benchmarks-baseline.json`
- **Formal proofs** (kernel modules): `cargo creusot` verifies all contracts
- **TLA+ specs** (kernel modules): `tlc` model check passes for every spec in `spec/`

## Testing strategy

- **Unit tests**: co-located with source in `#[cfg(test)] mod tests`.
- **Integration tests**: `crates/<crate>/tests/<topic>.rs`, exercise the public API.
- **Property tests**: `proptest` in `crates/<crate>/tests/properties.rs`.
- **Fuzz targets**: `crates/<crate>/fuzz/fuzz_targets/<name>.rs` with corpora in `crates/<crate>/fuzz/corpus/<name>/`.
- **Benchmarks**: `crates/<crate>/benches/<topic>.rs` using `criterion`.
- **Chaos tests**: `tests/chaos/` at workspace root.
- **Load tests**: `tests/load/` at workspace root; executed in nightly CI.

## Architecture decision records

Any change that alters architectural shape, public API, or cross-module contracts requires an **Architecture Decision Record** in `docs/adr/NNNN-<kebab-title>.md`. The ADR template is `docs/adr/0000-template.md`.

The ADR must cover:

- Context (the problem being solved)
- Decision (what is chosen)
- Rationale (why the alternatives were rejected)
- Consequences (what changes downstream)
- Alternatives considered (named and scored)
- References (related ADRs, upstream issues, papers)

The pull request body must link the ADR.

## Documentation

- **Public API** items require `#[doc]` attributes. `#![warn(missing_docs)]` is enforced at crate level.
- **Book chapters** live in `docs/book/` and follow `mdBook` structure.
- **Runbook and postmortems** live in `docs/ops/`.
- **Threat model** entries go in `docs/security/threat-model.md`.
- **Compliance mappings** live in `docs/compliance/`.

## Pull request checklist

Before requesting review, ensure the pull request:

- [ ] Targets the correct base branch (`develop` for features, `main` for hotfixes only)
- [ ] Passes every quality gate locally
- [ ] Links an ADR when the change affects public API or architecture
- [ ] Updates `CHANGELOG.md` under the `[Unreleased]` section
- [ ] Updates `docs/api-surface.md` if the public API changed
- [ ] Includes tests covering new or changed behavior
- [ ] Includes benchmarks if performance-sensitive
- [ ] Includes fuzz targets if parsers or untrusted input boundaries are touched
- [ ] Carries GPG-signed commits
- [ ] Uses Conventional Commits format

## Review process

- Intra-sprint feature pull requests are reviewed manually and via the `/review` automated review.
- The final `develop → main` pull request for a release is reviewed via `/ultrareview` once per release cycle.
- Reviewers look for: correctness, missing edge cases, security implications, performance regressions, adherence to conventions, documentation, test quality.

## Licensing of contributions

By submitting a pull request, contributors agree to license their contribution under the [Apache License, Version 2.0](LICENSE), the same license as the rest of the project.

The "Pulsar Framework" name and logo are registered trademarks held by the project owner. Contributions do not transfer trademark rights and must comply with the [trademark policy](docs/trademark-policy.md).

## Security-sensitive contributions

If a contribution may reveal or mitigate a security vulnerability, do **not** open a public pull request first. Follow the [security disclosure process](SECURITY.md) to coordinate a private patch.

## Getting help

- Feature discussions: [GitHub Discussions](https://github.com/LennyObez/pulsar-framework/discussions)
- Bug reports: [GitHub Issues](https://github.com/LennyObez/pulsar-framework/issues)
- Real-time chat: (link to be added after GA launch)
