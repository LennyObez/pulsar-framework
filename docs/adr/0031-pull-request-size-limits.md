# ADR-0031: Pull Request size limits and release-PR scope policy

## Status

Accepted (effective immediately, blocks 1.0.0 GA tagging if violated)

## Context

Audit findings **F33.M2**, **F385.M1**, **F385.M3**, **F387.M1** — and the patterns observed across PRs #33 (51 K LOC, 1 reviewer), #380 (290 K LOC, closed), #385 (408 K LOC, 50 commits squashed/merged into the rc.11 release), and the open PR #387 (243 K LOC, 2 296 files, 1.0.0 GA target) — establish that the project's de-facto release process bundles whole release cycles into single mega-PRs.

For a framework targeting banking / healthcare / legal compliance, "PR was peer reviewed" is a recurring claim in the audit trail. A 400 K-line diff cannot be reviewed in any meaningful sense by any reviewer, regardless of seniority. The claim therefore documents itself as fiction — and that fiction is the part regulators will pull on.

ADR-0001 governance commits to ADR-bound architectural change but says nothing about PR sizing or release cadence. This ADR fills that gap.

## Decision drivers

1. **Reviewability.** Empirically, code-review effectiveness collapses past ~500 lines of diff and is nil past a few thousand. A peer-review claim that does not survive the empirical reality is a compliance hazard.
2. **Bisection.** Squash-merging a release-PR of 50 commits into a single squash commit destroys `git bisect` granularity. When a regression lands, the "blame" target is a 400 K-LOC commit with no actionable owner.
3. **Audit traceability.** PCI-DSS, SOX, and the EU CRA expect change-control documentation that links each defect to the discrete change that introduced it. Mega-PRs erase that link.
4. **Reflection of intent.** A `chore:` title for a PR that introduces four new core modules (workflow, saga, codegen, service-discovery) — observed in PR #387 — undermines automated release-notes generation and signals to readers that the title is not load-bearing. Conventional Commits enforcement only works when the prefix matches the actual scope.

## Decision

### 1. Hard cap: feature PRs must not exceed 1 500 LOC of diff.

A PR that exceeds 1 500 lines of *substantive* diff (excluding generated code, vendored fixtures, lockfile updates, and pure rename diffs) MUST be split before merge. CI enforces the cap by counting `git diff --shortstat` excluding paths in `.size-limit-ignore`.

**Rationale for 1 500.** Below 500 LOC, review effectiveness is high; 500–1 500 covers most legitimate feature slices; above 1 500, splitting is feasible and the cost of refactoring the PR is dwarfed by the cost of an undetected regression in a regulated domain.

Exceptions require:
- explicit `oversize-pr-acknowledged` label,
- maintainer comment in the PR thread referencing this ADR,
- independent reviewer attestation (different from the PR author *and* the merging maintainer).

### 2. Soft cap: feature PRs should target ≤ 500 LOC where feasible.

When ≤ 500 LOC is achievable without artificial splitting, prefer it. The CI check at 1 500 is the line; ≤ 500 is the goal.

### 3. Release PRs must be minimal.

A release PR (the PR that bumps `composer.json`, `Version::PRERELEASE_SUFFIX`, and adds the `CHANGELOG.md` entry for an `rc.x` or `1.0.0` tag) MUST be ≤ 100 LOC of substantive diff. If it touches feature code, that feature code did not belong in the release PR — it should have shipped in a prior feature PR and been listed in the changelog.

Concretely, an `rc.x` or GA release PR may modify:
- `composer.json` (version field, possibly lock-file cascade),
- `composer.lock` (regenerated),
- `src/Core/Version.php` (`PRERELEASE_SUFFIX` constant),
- `CHANGELOG.md` (additions).

Anything else is a bundling violation and the release PR is rejected.

### 4. Title and prefix MUST reflect scope.

Conventional Commits is already project policy (CLAUDE.md). The strict additional rule for PRs:

- A PR that introduces a new module under `src/<NewModule>/` or a new `src/<Existing>/<NewSubsystem>/` MUST use the `feat(...)` prefix.
- `chore(...)`, `docs(...)`, `refactor(...)` prefixes MUST NOT be used on PRs that add features. F387.M2 (`chore:` on a PR introducing 4 core modules) is the canonical violation.
- The `(<scope>)` parenthetical MUST name the area: `feat(core)`, `feat(ext)`, `security(http)`, etc. Multiple scopes are allowed via slash separator: `feat(core/ext)`.

CI lints titles against this rule using the existing Conventional Commits checker; the strict-prefix-on-new-modules check is added as a new rule in the same hook.

### 5. Release-PR-attempt-and-close events MUST be documented.

PR #330 (closed without merge, replaced by PR #385) and PR #380 (290 K-LOC docs cleanup, closed) created audit gaps where regulators can ask "why was the release attempted then withdrawn?" and find no answer.

When a release PR is closed without merging, the maintainer who closes it MUST leave a comment on the PR explaining (a) the reason for closure, (b) what the replacement plan is, (c) whether any of the changes were salvaged into other PRs. CI cannot enforce a free-text policy, but the absence of such a comment is a release-process violation surfaced in the next release retrospective.

### 6. The 1.0.0 GA tag is GATED on this ADR being satisfied.

Specifically:
- PR #387 (or its successors) MUST be split before merge per §1. The currently-open 243 K-LOC mega-PR is not eligible for merge under this policy.
- The release PR that lands the GA tag MUST satisfy §3.
- All commits between the last `rc.x` tag and the GA tag MUST be reachable individually via `git log`, not collapsed into one squash.

## Implementation plan

### Phase 1: CI enforcement (this PR / next chore PR)

- Add `tools/ci/check-pr-size.sh` that runs `git diff --shortstat origin/main...HEAD` against the cap and exits non-zero on violation. Wire into the existing PR-checks workflow.
- Extend the Conventional Commits title linter to flag `chore(...)` / `docs(...)` / `refactor(...)` titles on PRs that add files matching `src/[A-Z][a-zA-Z]*/` (new top-level modules) or `src/<existing>/[A-Z][a-zA-Z]*/` (new subsystems).

### Phase 2: backlog audit (during rc.12 cycle)

- Open the rc.11 release-PR meta-issue: enumerate the 50 commits in PR #385 and assign each to a reviewer for retrospective review. Findings flow back into `.claude/findings.md` under the `Session 10 long-tail` heading.

### Phase 3: GA gate (before tagging 1.0.0)

- The release manager confirms in the GA release-PR description that this ADR's §3 + §6 are satisfied. The confirmation is itself the audit artefact.

## Consequences

### Positive

- Restores the credibility of "peer reviewed" as a documented control for regulated deployments.
- Restores `git bisect` granularity for incident response.
- Aligns release management with banking-grade change-control expectations.
- Closes audit findings F33.M2, F385.M1, F385.M3, F387.M1 by establishing the policy whose absence those findings flagged.

### Negative

- Adds CI friction; some legitimate large feature work will require more PR engineering (inter-PR dependency tracking via `Stacked PRs` or similar tooling).
- The rc.12 retrospective on PR #385 is non-trivial work (50 commits to audit retrospectively).
- PR #387 cannot merge as-is. Its outstanding work must be split.

### Neutral

- The 1 500 LOC cap is calibrated for feature work in a typed/tested PHP codebase; it does not apply to extension or downstream-app code, which sets its own policies.

## Tracking

- Audit findings closed: F33.M2, F385.M1, F385.M3, F387.M1, F387.M2 (Conventional Commits scope alignment).
- CI workflow: `.github/workflows/pr-checks.yml` (extend with `check-pr-size.sh`).
- Owner: release manager.
- Blocking: 1.0.0 GA tag.

## Cross-references

- CLAUDE.md "Commit & Branch Workflow" — refines that section's scope policy.
- ADR-0001 governance — this ADR is a derived implementation of the "documented architectural commitment" principle.
- ADR-0030 (WebAuthn library adoption) — also a 1.0.0 GA blocker; both ADRs gate the same tag.
