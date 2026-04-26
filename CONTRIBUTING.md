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

- Commits must be **GPG-signed** (Ed25519 key preferred per plan Decision 2.30)
- Commit messages follow **Conventional Commits v1.0.0**: `<type>(<scope>): <imperative summary ≤ 72 chars>`
  - Types: `feat`, `fix`, `docs`, `perf`, `refactor`, `test`, `ci`, `build`, `chore`, `security`, `deps`
  - Scopes: any of the 53 first-party crate names without the `pulsar-` prefix (e.g. `kernel`, `http`, `orm`, `auth`, `audit`, `compliance`, `observability`, `search`, `guard`, `mail`, `queue`, `scheduler`, `cache`, `config`, `storage`, `form`, `webhook`, `idempotency`, `notification`, `pagination`, `feature-flag`, `i18n`, `tenancy`, `dataprotection`, `consent`, `authz`, `identity-standards`, `cms`, `forum`, `payments`, `console-api`, `api`, `graphql`, `grpc`, `mcp-server`, `realtime`, `ai`, `ai-governance`, `vector-search`, `ai-agents`, `live`, `studio`, `analytics`, `accessibility`, `orchestration`, `cloud`, `edge`, `cluster`, `deploy`, `cli`, `test`, `framework` for the meta-crate); plus `workspace` (root Cargo.toml), `ci` (GitHub Actions workflows), `docs` (plan, ADR, book), `adr` (single ADR), `arch` (architecture diagrams)
- Body wraps at 72 columns
- No `Co-Authored-By` trailers
- No reference to automated drafting tooling in any committed artefact
- Pull requests target `develop`; the `develop → main` merge is reserved for the terminal GA release via `/ultrareview` (plan Section 2.6)

## Quality gates (non-negotiable)

Before opening a pull request, run the full gate suite locally. Every item must pass.

The full per-sprint quality-gate suite is documented in [docs/plan.md](docs/plan.md) Section VI. Run locally before opening a pull request:

```bash
cargo fmt --all -- --check
cargo clippy --workspace --all-targets --all-features -- -D warnings
cargo check --workspace --all-targets --all-features
cargo nextest run --workspace --all-features
cargo test --workspace --doc
cargo llvm-cov --workspace --lcov --fail-under-lines 100      # kernel, security, compliance, data protection crates
cargo llvm-cov --workspace --lcov --fail-under-lines 95       # other crates
cargo llvm-cov --workspace --branch --fail-under-branches 100 # kernel, security, compliance, data protection
cargo llvm-cov --workspace --mcdc --fail-under-mcdc 100       # kernel, security, compliance
cargo mutants --workspace --minimum-test-timeout 60           # ≥ 95% on kernel/security/compliance/data-protection
cargo fuzz run <target> -- -runs=10000000                     # parser sprints
cargo audit                                                    # zero advisories
cargo deny check advisories bans licenses sources
cargo machete                                                  # zero unused deps
cargo bench --workspace                                        # criterion comparison vs baseline
cargo creusot                                                  # kernel sprints only
tlc -config spec/<name>.cfg spec/<name>.tla                   # kernel + Sprint 2.5 + 3B.2 + 3E.1
```

Per-module requirements:

- **Line, branch, MC/DC coverage**: 100% on `pulsar-kernel`, `pulsar-guard`, `pulsar-compliance`, `pulsar-dataprotection`; ≥ 95% line/branch on every other crate
- **Mutation kill rate** (`cargo mutants`): ≥ 95% on kernel/security/compliance/data-protection; ≥ 90% on every other crate
- **Fuzz targets** (every parser sprint): `cargo fuzz run <target> -- -runs=10000000` must complete with zero crashes
- **Benchmark regression** (`criterion`): no P99 regression above 5% versus the committed baseline in `docs/perf/baselines/<version>/`
- **Formal proofs** (kernel sprints): `cargo creusot` verifies every annotated contract
- **TLA+ specs**: `tlc` model check passes for every relevant `spec/<name>.tla` (nine specs total at GA per plan Section XII)
- **OpenSSF Scorecard**: ≥ 9.0 sustained for 30 days before each release per plan Section 16.12.8

## Testing strategy

- **Unit tests**: co-located with source in `#[cfg(test)] mod tests`.
- **Integration tests**: `crates/<crate>/tests/<topic>.rs`, exercise the public API.
- **Property tests**: `proptest` in `crates/<crate>/tests/properties.rs`.
- **Fuzz targets**: `crates/<crate>/fuzz/fuzz_targets/<name>.rs` with corpora in `crates/<crate>/fuzz/corpus/<name>/`.
- **Benchmarks**: `crates/<crate>/benches/<topic>.rs` using `criterion`.
- **Chaos tests**: `tests/chaos/` at workspace root.
- **Load tests**: `tests/load/` at workspace root; executed in nightly CI.

## Naming and coding conventions

The authoritative naming and coding catalogue is **plan Section XVII** ([docs/plan.md](docs/plan.md)). It governs:

- Crate naming (`pulsar-<subsystem>` kebab-case, semantic suffixes)
- Module layout within a crate (`src/lib.rs`, `src/error.rs`, `src/sealed.rs`, per-subdomain directories)
- Type naming (PascalCase with semantic suffix: `Error`, `Builder`, `Policy`, `Adapter`, `Provider`, `Registry`, `Store`, `Context`, `Handler`, `Middleware`, `Dispatcher`; avoid `Manager`, `Helper`, `Util`, `Service`, `Data`, `Info`, `Impl`)
- Trait naming (noun-form for roles, `-er` for actions, `-able` for properties)
- Method naming (`new`, `build`, `from_*`, `into_*`, `as_*`, `to_*`, `is_*`, `has_*`, `with_*`, `set_*`; never `get_*`)
- Field naming (snake_case, durations suffixed with unit `_ms`/`_seconds`, IDs as newtype wrappers, timestamps `created_at`/`updated_at`/`deleted_at`)
- Error strategy (one public `Error` enum per crate using `thiserror`; `anyhow` only in `pulsar-cli`)
- Feature flag naming (kebab-case)
- Configuration key naming (snake_case TOML)
- Environment variable naming (`PULSAR_<SUBSYSTEM>_<NAME>` SCREAMING_SNAKE_CASE)
- Metric naming (Prometheus + OpenMetrics conventions)
- HTTP header naming (`Pulsar-<Name>` per RFC 6648, no `X-` prefix)
- Audit event naming (past-tense DDD, e.g. `UserAuthenticated`, `OrderPlaced`)
- Database table + column naming
- Migration naming
- Proc-macro naming
- Public API stability markers (`#[api(since)]`, `#[experimental]`, `#[deprecated]`, `#[internal]`)
- Testing conventions
- Benchmark conventions
- Logging conventions
- Unsafe code policy
- Dependency discipline

Deviations from Section XVII require an explicit ADR.

## Architecture decision records

Any change that alters architectural shape, public API, or cross-module contracts requires an **Architecture Decision Record** in `docs/adr/NNNN-<kebab-title>.md`. The ADR template is `docs/adr/0000-template.md` (lands at Sprint 0.5).

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
