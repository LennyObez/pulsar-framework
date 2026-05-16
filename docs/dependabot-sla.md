# Dependabot security PR policy and SLA

This document records the project's policy for handling Dependabot
security PRs. Closes audit findings **F388.1**, **F388.2**, and the
meta-finding **F388.M1**.

## Background

Audit reviews observed that Dependabot security PRs against the
framework were repeatedly closed without merge while the maintainer
applied a manual, intermediate-version bump several days later — the
canonical case being `league/commonmark`:

- PR #386 (Dependabot, `2.8.0` → `2.8.1`) — closed without merge.
- The maintainer manually bumped to `2.8.1` on `chore/quality-and-performance`.
- PR #388 (Dependabot, `2.8.0` → `2.8.2`) — closed without merge three
  days later.
- The maintainer eventually bumped to `2.8.2` (the version this branch
  ships).

For a framework targeting banking / healthcare / legal compliance the
gap between upstream patch availability and project patch is a
PCI-DSS 6.3.3 and EU CRA reportable concern. Without a documented
policy a future regression of this pattern is invisible to outside
reviewers.

## Decision

### 1. Default disposition: accept Dependabot security PRs

Dependabot security PRs (PRs whose label set includes
`security` or whose body identifies a CVE / GHSA reference) MUST be
either:

- **merged** within the SLA below, or
- **explicitly closed with a maintainer comment** that records the
  compensating control and the planned remediation.

"Closed without merge and without comment" is no longer an acceptable
disposition for security PRs.

### 2. SLA by severity

Severity is read from the GHSA / Dependabot PR body:

| Severity (GHSA)     | Merge SLA                 |
| ------------------- | ------------------------- |
| Critical (9.0–10.0) | 48 hours from PR creation |
| High (7.0–8.9)      | 7 calendar days           |
| Medium (4.0–6.9)    | 14 calendar days          |
| Low (0.1–3.9)       | 30 calendar days          |

If CI fails on a Dependabot PR, the SLA pauses while the maintainer
fixes the breakage on a follow-up branch and re-targets the bump.
The maintainer comment on the original PR records the diversion so
the audit trail shows why the original PR did not merge directly.

### 3. Compensating control = explicit, comment-tracked

When an upstream patch cannot be applied within the SLA — typically
because the patch introduces a breaking change the framework cannot
absorb in the available window — the maintainer leaves a comment on
the Dependabot PR that records:

- **What the unpatched vulnerability is** (CVE / GHSA reference).
- **What the compensating control is** (e.g. WAF rule, input
  sanitisation in middleware, feature-flag disabling the affected
  code path).
- **When the proper fix is scheduled** (issue/PR number tracking the
  follow-up).

The PR is then closed with a label `security-deferred` so dashboards
can surface the still-open exposure.

### 4. Manual intermediate-version bumps require justification

The pattern observed in PR #386 / #388 — Dependabot offers `2.8.2`,
the maintainer rejects and manually bumps to `2.8.1` first — is
explicitly discouraged because:

- It doubles the work (two PRs vs one).
- It widens the exposure window (the project sits on the older fix
  longer than necessary).
- It signals to outside reviewers that the project has a process the
  maintainer disagrees with — better to fix the process.

When a manual intermediate bump IS applied (e.g. `2.8.2` was found
to contain an unrelated regression), the manual-bump PR description
MUST link the original Dependabot PR and explain why the latest
upstream version was not adopted. Closing the Dependabot PR without
explanation is a process violation surfaced in the next release
retrospective.

### 5. Auto-merge eligibility

A Dependabot PR is eligible for auto-merge when:

- The targeted package is in the `auto-merge-allowlist` (`dependabot.yml`);
- CI is fully green (PHP quality gates, test suite, security scans);
- The PR is a patch or minor bump of a package whose changelog the
  maintainers reviewed at intake time.

Major version bumps and bumps of packages outside the allowlist are
manual-review-only.

## Scope

This policy applies to:

- Dependabot security PRs against the `pulsar/framework` repo.
- Dependabot security PRs against bundled extensions
  (`extensions/*/composer.json`) when their lockfile is shared with
  the framework's root lockfile.

It does NOT apply to non-security version bumps (Dependabot's regular
update cadence), which follow the standard PR-size and review-
cadence policies described in ADR-0031.

## Implementation

Phase 1 (this document): record the policy.

Phase 2 (next quality sprint): translate the policy into machine-
readable rules:

- `dependabot.yml` schedules tuned per the SLA.
- A GitHub Actions workflow that reads PR labels and posts a reminder
  comment when the SLA is approaching expiry.
- The `security-deferred` label registered with consistent metadata.

## Cross-references

- ADR-0031 — Pull-request size limits (separate cap for non-security
  bumps; this policy supersedes for the security path).
- Audit finding F386.1 — the original commonmark patch.
- Audit finding F388.1 — manual intermediate-version bump pattern.
- Audit finding F388.2 — recurring Dependabot security PR rejections.
- Audit finding F388.M1 — meta-finding that prompted this policy.
- PCI-DSS 6.3.3 — timely security patches (regulator anchor).
- EU CRA Article 13 — vulnerability handling obligations.
