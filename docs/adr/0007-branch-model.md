# ADR-0007: Branch model with `main` stable, `develop` integration, sprint feature branches

* **Status:** Accepted
* **Date:** 2026-04-26
* **Driver:** Lenny Obez
* **Related Section II decision(s):** Decision 2.5 (Branch model: main stable, develop active integration, feat/sprint-N-M-topic), Decision 2.6 (Review protocol: /review intra-feature, /ultrareview final)
* **Sprint:** Sprint 0.5 (initial ADR batch)
* **Supersedes:** none

## Context

Pulsar Framework spans an eleven-phase rewrite (Phase 0 through Phase 4) per plan Section V, ships 53 first-party crates per Section IV, and integrates with the v0.99.0-php-final history per Decision 2.4. Two branch-model questions drive the choice:

1. **How does in-progress work integrate without disrupting consumers of the released artefact?** During the multi-year rewrite (Decision 2.48), the framework must accept sprint-by-sprint integration without exposing partial features as stable.
2. **How is the terminal release event (1.0.0 GA) staged?** The plan's big-bang merge strategy (per Section I) requires a single review event covering the entire rewrite, distinct from the per-sprint review events.

Three branch models are commonly used:

* **Trunk-based development** — single long-lived branch; every commit must be releasable.
* **Git Flow** (Vincent Driessen, 2010) — `master`, `develop`, `feature/*`, `release/*`, `hotfix/*`.
* **GitHub Flow** — single long-lived `main` plus topic branches; release via tags.

Pure trunk-based forces every kernel-formal-verification commit to be releasable, which conflicts with the Sprint 1.x cadence that gradually proves the kernel. Pure GitHub Flow lacks the integration-branch semantic that the multi-phase rewrite needs. Git Flow is closer but heavyweight (multiple long-lived branches: master + develop + release/*).

## Decision

Pulsar Framework adopts a **three-tier branch topology**:

* **`main`** — holds shipped tags only. No direct commits. The branch advances exactly once at GA via the `/ultrareview` big-bang merge from `develop`. Subsequent post-GA releases also merge from `develop` to `main`, one merge per release.
* **`develop`** — the active integration branch for the entire pre-GA period and for ongoing post-GA development. All sprint work integrates here.
* **`feat/sprint-N-M-topic`** — short-lived feature branches for each sprint. Format: `feat/sprint-<phase>-<sprint-within-phase>-<topic-slug>`. Examples: `feat/sprint-0-1-meta-files`, `feat/sprint-1-1-crypto`, `feat/sprint-3B-2-realtime`.

Auxiliary branch namespaces (per CONTRIBUTING.md):

* **`fix/<issue>-<slug>`** — bug-fix branches off `develop` or `main`.
* **`security/*`** — security-sensitive branches (private when appropriate per ADR-0008-style audit invariant).
* **`docs/*`** — documentation-only changes.
* **`chore/*`** — tooling, build, dependency updates.

Merge strategy:

* **`feat/*` → `develop`**: squash-merge with Conventional Commits subject. Keeps `develop` history clean (one commit per merged PR).
* **`develop` → `main`**: standard merge (preserving signed commit topology). One merge commit per big-bang release event; in practice exactly one such merge at GA, then per-release post-GA.

Protected-branch policy on both `main` and `develop`:

* No force push.
* No direct commits.
* Linear history required.
* All status checks required: `ci / fmt`, `ci / clippy`, `ci / check`, `ci / nextest`, `ci / llvm-cov`, `ci / deny`, `ci / audit`, `ci / docs`, `ci / adr-index`, `ci / changelog-entry`, `review / approved`. On `develop` and `main` additionally: `benchmark / no-regression` and, in kernel sprints, `formal / creusot` and `formal / tlc`.
* Administrators included in restrictions.

## Consequences

### Positive

* Every sprint has a single canonical branch for review (`feat/sprint-N-M-topic`).
* `develop` is the source of truth for pre-GA work; downstream consumers can opt in to bleeding-edge by tracking `develop` or wait for `main` for stable.
* Big-bang `develop → main` merge concentrates the architecture-scale review event (`/ultrareview` per Decision 2.6) at one well-defined point per release.
* Squash-merge into `develop` keeps the integration history readable: one commit per shipped sprint.
* Branch protection rules prevent force-push and direct commits, eliminating the most common destructive git mistakes.
* Conventional Commits + GPG-signed commits + the changelog-entry CI gate make every merged commit auditable.
* The branch model maps cleanly to the GitHub Pull Request UI; no custom tooling required.

### Negative

* `develop` is long-lived (multi-year per Decision 2.48). Long-lived branches accumulate divergence; mitigated by frequent sprint merges (small batch).
* Squash-merge loses individual commit attribution within a feature branch (only the squash commit survives on `develop`). Acceptable trade-off for `develop` readability; the original feature-branch commits remain on the branch.
* The big-bang `develop → main` merge is intentionally single-shot; if it surfaces issues during `/ultrareview`, the GA calendar absorbs the remediation cycle (per plan Section 11.8 Checkpoint 4).
* Three branch tiers are more ceremony than GitHub Flow's two. Mitigated by explicit Decision 2.5 making the choice once and not relitigating.

### Neutral

* The branch model is independent of the work tracking system; GitHub Issues, project boards, or external trackers can map to feature branches without changing the model.
* Squash-merge naming: the squash commit subject follows Conventional Commits format (per Decision 2.30) and preserves the sprint context in the body.

## Alternatives considered

* **Trunk-based development (single `main`, every commit releasable).**
  Rejected: forces partial-merge discipline incompatible with the kernel-formal-verification phase. Sprint 1.1 (crypto primitives) cannot be "releasable" until the full kernel exists.
* **Pure GitHub Flow (single `main`, topic branches via PR).**
  Rejected: lacks the `develop` integration branch semantic. The multi-phase rewrite needs an integration branch distinct from the release branch so that pre-GA artefacts can be tagged for namespace reservation (Sprint 0.8 v0.0.1-alpha.0) without those tags landing on the stable `main` history.
* **Full Git Flow (with `release/*` and `hotfix/*` long-lived branches).**
  Rejected: `release/*` branches add ceremony for releases that ship from `develop` directly. Rejected per Decision 2.5 in favour of the simpler three-tier model.
* **GitLab Flow with environment branches (`main`, `pre-production`, `production`).**
  Rejected: not applicable to a framework distributed via crates.io rather than deployed to environments.
* **OneFlow (one long-lived branch, release branches forked off when needed).**
  Considered. Differences from chosen model are minor; chosen model preserves explicit `develop` semantic that aligns with the established Git Flow convention contributors recognise.

## References

* Plan section(s): `docs/plan.md` Section II Decision 2.5 + 2.6, Section VIII GitFlow Strategy, Section IX release strategy (per-phase tagging).
* Risk register entries: R-019 (cross-crate API consistency — branch protection prevents accidental surface drift).
* Related ADRs: ADR-0008 (GitFlow sprint branches — companion ADR formalising the sprint-branch contract), ADR-0005 (Apache-2.0 licence — sets the commit-attribution constraints), ADR-0006 (crates.io namespace).
* External:
  * Driessen, V. "A successful Git branching model." nvie.com/posts/a-successful-git-branching-model, 2010 (Git Flow).
  * GitHub. "GitHub Flow." docs.github.com/get-started/quickstart/github-flow.
  * Conventional Commits v1.0.0. conventionalcommits.org/en/v1.0.0.
  * GPG signing for commits. docs.github.com/authentication/managing-commit-signature-verification.

## Compliance mapping

The branch model + branch protection + GPG-signed commits collectively constitute the change-management control surface that regulators evaluate when auditing software-development processes. Every framework targeting regulated deployment must demonstrate "code changes are reviewed, attributed, and tamper-evident" — the three-tier branch topology is Pulsar's evidence.

* **ISO/IEC 27001:2022 A.8.32** (change management) — `develop` integration branch + sprint feature branches + required PR review constitute the documented change-management process.
* **ISO/IEC 27001:2022 A.5.4** (management responsibilities) — branch protection enforces the maintainer-approval requirement on every change to `main`.
* **ISO/IEC 27001:2022 A.8.4** (access to source code) — branch protection rules + CODEOWNERS enforce least-privilege access at the repository level. CODEOWNERS auto-assigns the maintainer to critical-coverage-tier crates so review responsibility is unambiguous.
* **ISO/IEC 27034-3:2018** (application security management process — § 7 verification) — required status checks (fmt + clippy + check + nextest + llvm-cov + deny + audit) on PRs gate the change-management workflow.
* **PCI-DSS 4.0 Req. 6.5.1** (changes follow change-control procedures) — sprint-branch contract + required reviews + GPG-signed commits + linear history on `main` collectively satisfy 6.5.1.
* **PCI-DSS 4.0 Req. 6.5.2** (separation of duties between development and production) — `develop` (integration / development) vs `main` (release / production) is the technical separation; commits to `main` only via reviewed PRs from `develop`.
* **PCI-DSS 4.0 Req. 6.5.3** (segregation of pre-production and production environments) — tags on `main` are immutable; rebuilds from a tag are reproducible (per Section 14.6 SLSA L4 + repro-build.yml).
* **PCI-DSS 4.0 Req. 6.5.4** (separation of duties between personnel assigned to pre-production and production environments) — single-maintainer Phase 0 is acknowledged residual risk; CODEOWNERS already encodes the separation-of-duties seam for when a contributor pool emerges.
* **DORA Art. 9(2)(c)** (preventive measures including authentication of users and devices, and secure system development life cycle) — branch model + GPG signing + Trusted Publisher OIDC are the SSDLC controls.
* **NIST SP 800-218 SSDF — PS.1.1** (protect repositories from unauthorised changes) — branch protection + required signed commits.
* **NIST SP 800-218 SSDF — PS.2.1** (provide a mechanism for verifying software release integrity) — annotated GPG-signed tags on `main` are the verification mechanism.
* **NIST SP 800-53 Rev. 5 CM-3** (configuration change control) — branch model is the documented, approved, audited change-control process.
* **NIST SP 800-53 Rev. 5 CM-5** (access restrictions for change) — branch protection + CODEOWNERS enforce CM-5.
* **EU CRA Annex I § 1(g)** (vulnerabilities can be addressed through security updates, including, where applicable, automatic updates) — `develop` → `main` cadence supports rapid security-patch shipping when CVEs land.
* **NIS2 Art. 21(2)(e)** (security in network and information systems acquisition, development and maintenance) — branch model + signed commits + reproducible builds collectively satisfy the SSDLC requirements.
