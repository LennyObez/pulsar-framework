# ADR-0008: Sprint feature branch contract — `feat/sprint-N-M-topic` with squash-merge

* **Status:** Accepted
* **Date:** 2026-04-26
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.5 (Branch model), Decision 2.6 (Review protocol: /review intra-feature, /ultrareview final), Decision 2.30 (Commit policy: GPG-signed, Conventional Commits, no Co-Authored-By, no automated drafting tooling references)
* **Sprint:** Sprint 0.5 (initial ADR batch)
* **Supersedes:** none

## Context

ADR-0007 sets the three-tier branch topology (`main` / `develop` / `feat/sprint-N-M-topic`). This ADR defines the **sprint feature branch contract**: how a sprint is named, scoped, reviewed, merged, and closed. The contract must satisfy four properties:

1. **Discoverability.** Anyone reading `git branch -a` can locate every active sprint and identify its phase, sprint-within-phase, and topic.
2. **Reviewability.** Every sprint exits with a single PR that bundles all related commits and surfaces the diff against `develop` for `/review` (Decision 2.6).
3. **Auditability.** Every merged commit on `develop` is GPG-signed, follows Conventional Commits, and references the originating sprint in the body.
4. **Reversibility (within reason).** A merged sprint can be reverted via standard `git revert` without unwinding subsequent sprints. This requires the squash-merge model so each sprint is a single revertable commit on `develop`.

The plan's eleven-phase structure (Phase 0 through Phase 4) and per-phase sprint enumeration (per Section V) drive the naming convention.

## Decision

Pulsar Framework sprints follow a strict feature-branch contract.

### Branch naming

Format: `feat/sprint-<phase>-<sprint-within-phase>-<topic-slug>`

Where:

* **`<phase>`** is the phase number per plan Section V: `0`, `1`, `1.5`, `2A`, `2B`, `2C`, `3A`, `3B`, `3C`, `3D`, `3E`, `4`. Phase 1.5 spells as `1-5` in the branch name (because slashes in branch names complicate refspecs); thus `feat/sprint-1-5-guard`.
* **`<sprint-within-phase>`** is the per-phase sprint index per plan Section V: `1`, `2`, ..., `13`. For phases with a single sprint (e.g. Phase 1.5 post-consolidation has one sprint), the index is omitted: `feat/sprint-1-5-guard`.
* **`<topic-slug>`** is a short kebab-case topic identifier matching the sprint name in plan Section V. Examples: `meta-files`, `crypto`, `audit`, `session`, `router`, `middleware`, `container`, `guard`, `http`, `engine`, `orm`, `auth`, `compliance`, `observability`, `search`, `cms`, `forum`, `payments`, `console-api`, `realtime`, `orchestration`, `cluster`, `cli`, `wasm-sandbox`, `marketplace`, `exhaustive-tests`, `docs-consolidation`, `audit-ga`, `fips-validation`, `confidential-computing`, `governance`, `privacy-enhancing-tech`.

Auxiliary branches (per CONTRIBUTING.md):

* `fix/<issue>-<slug>` for bug fixes (off `develop` or `main`).
* `security/*` for security-sensitive branches.
* `docs/*` for documentation-only.
* `chore/*` for tooling/build/dep updates outside a sprint.

### Sprint lifecycle

1. **Plan mode first.** Read `docs/plan.md` for the relevant Sprint section. Confirm scope, exit criteria, acceptance tests.
2. **Branch.** `git checkout -b feat/sprint-<N>-<M>-<topic>` from `develop`.
3. **TDD.** Write tests first. Red, green, refactor.
4. **Implement.** Follow Section XVII naming + coding standards strictly.
5. **Quality gates.** Run the full Section VI sequence locally. Fix every error.
6. **ADR if required.** Changes to core architecture or Section II decisions trigger a new ADR in `docs/adr/`.
7. **API surface.** If public surface changed, regenerate `docs/api-surface.md`.
8. **CHANGELOG.** Add entry under `[Unreleased]`.
9. **Commit.** Conventional Commits subject ≤ 72 characters, body wraps at 72, GPG-signed Ed25519. No `Co-Authored-By` trailers. No reference to automated drafting tooling in any committed artefact.
10. **PR.** Open PR from `feat/sprint-*` to `develop`. Invoke `/review`.
11. **Stop.** Never push or merge without explicit user approval (per CLAUDE.md operator-manual rule).

### Merge

`feat/sprint-*` → `develop`: **squash-merge** with the squash commit subject formatted as `feat(sprint-N-M): <topic summary>` and the body containing the full per-commit changelog plus the sprint exit-criteria verification statement. The original feature-branch commits remain on the branch (not deleted automatically — destructive operations require explicit user authorisation per CLAUDE.md).

### Status checks (required for merge to `develop`)

* `ci / fmt` — `cargo fmt --all -- --check` exit 0
* `ci / clippy` — `cargo clippy --workspace --all-targets --all-features -- -D warnings` exit 0
* `ci / check` — `cargo check --workspace --all-targets --all-features` exit 0
* `ci / nextest` — `cargo nextest run --workspace --all-features --no-fail-fast` exit 0
* `ci / doctest` — `cargo test --workspace --doc` exit 0
* `ci / llvm-cov` — meets per-tier coverage thresholds (100 % critical, ≥ 95 % other)
* `ci / deny` — `cargo deny check advisories bans licenses sources` exit 0
* `ci / audit` — `cargo audit` exit 0
* `ci / machete` — `cargo machete` exit 0
* `ci / docs` — `cargo doc --workspace --no-deps --all-features` with `RUSTDOCFLAGS="-D warnings"` exit 0
* `ci / adr-index` — every ADR file referenced in `docs/adr/INDEX.md` exists
* `ci / changelog-entry` — `CHANGELOG.md` modified vs base ref (PR only)
* `review / approved` — `/review` skill output passes
* On kernel sprints additionally: `formal / creusot`, `formal / tlc` per affected spec.

### Tag policy

Per phase exit (per plan Section IX release strategy), the corresponding tag is issued on `develop`:

| Tag | Phase exit |
|-----|------------|
| `v0.0.1-alpha.0` | Phase 0 namespace reservation (Sprint 0.8) |
| `v0.1.0` | Phase 1 kernel exit (Sprint 1.6) |
| `v0.2.0` | Phase 1.5 security controls exit |
| `v0.5.0` | Phase 2A core runtime exit |
| `v0.6.0` | Phase 2B application infrastructure exit |
| `v0.7.0` | Phase 2C data protection + HSM exit |
| `v0.8.0` | Phase 3A application extensions exit |
| `v0.9.0` | Phase 3B API paradigms exit |
| `v0.10.0` | Phase 3C AI surface exit |
| `v0.11.0` | Phase 3D reactive + dev experience exit |
| `v0.12.0` | Phase 3E orchestration + infrastructure adapters exit |
| `v1.0.0-rc.N` | Phase 4 release candidates (on `develop`) |
| `v1.0.0` | GA on `main` after `develop → main` big-bang merge with `/ultrareview` |

Tags are annotated, GPG-signed, and trigger the `publish.yml` workflow (per ADR-0006 + Sprint 0.4 CI workflows).

## Consequences

### Positive

* Branch names self-document the originating sprint and topic — `git log --all --decorate --oneline --graph` is readable at a glance.
* Squash-merge gives `develop` a clean per-sprint history; `git bisect` regression hunting works at sprint granularity.
* Required status checks enforce every Section VI quality gate before merge — no path bypasses the gate.
* The lifecycle steps map cleanly to a checklist contributors can follow without ambiguity.
* Tag policy aligns with crates.io publish workflow and the namespace-reservation strategy.
* The single-shot `develop → main` merge at GA preserves the architecture-scale review event for `/ultrareview`.

### Negative

* The 12-step lifecycle is heavier than ad-hoc git workflow contributors may be used to. Mitigated by `.cargo/config.toml` aliases that wrap the quality-gate sequence.
* Squash-merge loses commit-level attribution on `develop`; the per-commit detail survives only on the feature branch.
* Branch-name encoding of phase (e.g. `1-5` for Phase 1.5) is a small naming compromise to avoid slash-in-branch-name complexity.
* The status-check list grows over phases (formal/creusot + formal/tlc add in kernel sprints, benchmark/no-regression adds on develop and main).

### Neutral

* The branch model is implementable without GitHub-specific features; works on GitLab, Gitea, etc. with equivalent protected-branch policies.
* `feat/*` branches are intentionally short-lived (single sprint); long-lived feature branches are out of scope.

## Alternatives considered

* **`feature/<phase-name>-<topic>` (without sprint number).**
  Rejected: phases span multiple sprints; without the sprint index, branch names collide.
* **`sprint/<N>-<M>-<topic>` (without `feat/` prefix).**
  Rejected: loses the type-of-work signal (`feat/` vs `fix/` vs `chore/`) that Conventional Commits encodes.
* **Slash-separated phase: `feat/sprint/1.5/guard`.**
  Rejected: nested slashes complicate Git refspec patterns and some CI tools' branch-trigger glob matching.
* **Rebase-merge instead of squash-merge.**
  Rejected: rebase rewrites commit hashes on `develop`, breaking the GPG signature chain that ADR-0008 invariants depend on.
* **Merge commit (no squash, no rebase).**
  Considered. Pros: preserves per-commit history on `develop`. Cons: messy `develop` history with intra-sprint review iteration commits visible. Squash-merge is the cleaner default; if a sprint needs per-commit history preserved, `git merge --no-ff` can be used as an exception (with ADR amendment).
* **No required status checks (review-only merge gate).**
  Rejected: human review is necessary but not sufficient for the assurance bar Pulsar targets. Per Decision 2.3 the quality gates are non-negotiable.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.5 + 2.6 + 2.30, Section VI quality gates, Section VIII GitFlow strategy, Section IX release strategy.
* Risk register entries: R-008 (scope creep), R-019 (cross-crate API consistency), R-020 (reproducible build determinism — squash-merge preserves digest reproducibility).
* Related ADRs: ADR-0007 (branch model — three-tier topology), ADR-0005 (Apache-2.0 licence — commit-attribution constraints), ADR-0006 (crates.io namespace — tag-driven publish flow).
* External:
  * Conventional Commits v1.0.0. conventionalcommits.org.
  * GitHub branch protection rules. docs.github.com/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches.
  * GPG-signed commits. docs.github.com/authentication/managing-commit-signature-verification.
* Compliance mapping: ISO 27001:2022 control A.8.32 (change management — sprint-branch contract is the technical change-management process); ISO 27001:2022 control A.8.34 (protection of information systems during audit testing — required status checks block uncontrolled merges).
