# Dependency security policy

> external audit finding **SEC-SC-02**: Dependabot security PRs cannot be closed
> without either a merging replacement or a documented waiver. This policy
> formalises the requirement and the workflow.

## Scope

Every dependency advisory raised by Dependabot (composer `require`, `require-dev`,
or `pnpm` packages) or by `composer audit` / `pnpm audit` in CI.

## Closing a Dependabot PR

A Dependabot PR may be closed only in one of three states:

1. **Merged** — the proposed bump is accepted and the security advisory is resolved.
2. **Superseded** — a new PR (e.g. a major-version bump that replaces the proposed
   patch-level bump) addresses the same advisory. The closing comment MUST link
   to the replacement PR with `Closes #N` referencing the original.
3. **Waived** — the maintainer determines the advisory does not apply (e.g. the
   vulnerable code path is not reachable from Pulsar). A waiver is added to
   `docs/security/dependency-waivers.md` with:
   - advisory ID (GHSA / CVE)
   - package name + version range
   - explicit unreachable-path rationale with file:line evidence
   - expiry date (waiver must be renewed every 90 days)

## Workflow enforcement

- The CI workflow `.github/workflows/dependency-audit.yml` runs `composer audit`
  and `pnpm audit` on every PR; failures block merge.
- A Dependabot PR closed without merge AND without a waiver entry triggers
  the `security-update-required` GitHub label automation: the label is applied
  to the next opened Dependabot PR (or a new tracking issue is created in
  Project #5 with priority P0).
- Quarterly review: maintainers walk the open advisory list with the
  `roave/security-advisories` dev dependency output and reconcile against
  this policy.

## Evidence required at PR review

Every Dependabot merge PR must include in its body:

- `composer audit` output excerpt confirming the advisory is resolved post-bump
- A 1-line note about the changelog category (security fix, breaking, etc.)

This evidence is part of the SOX ITGC trail for change management and the
SOC 2 control "vulnerability management — third-party software".

## See also

- `composer.json` — `roave/security-advisories: dev-latest` blocks installing
  any known-vulnerable transitive dependency at install time (defence in depth).
- `tools/security/semgrep-pulsar-rules.yml` — application-layer security rules.
- ADR-0006 — crypto library policy (libsodium only) related to vulnerable-
  algorithm advisories.
