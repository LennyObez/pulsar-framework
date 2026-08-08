# Dependabot security PR policy and SLA

This document records the project's policy for handling Dependabot
security PRs.

## Background

For a framework targeting banking / healthcare / legal compliance, the
gap between upstream patch availability and the project adopting that
patch is a PCI-DSS 6.3.3 and EU CRA reportable concern. The window has
to be bounded by policy and the disposition of every security PR has to
be legible from the outside — an unexplained close leaves downstream
consumers unable to tell a deliberate deferral from an oversight.

## Decision

### 1. Default disposition: accept Dependabot security PRs

Dependabot security PRs (PRs whose label set includes
`security` or whose body identifies a CVE / GHSA reference) MUST be
either:

- **merged** within the SLA below, or
- **explicitly closed with a maintainer comment** that records the
  compensating control and the planned remediation.

"Closed without merge and without comment" is not an acceptable
disposition for a security PR.

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

Rejecting the version Dependabot offers in order to hand-apply an
older patch release first is discouraged because:

- It doubles the work (two PRs instead of one).
- It widens the exposure window — the project sits on the older fix
  longer than necessary.

When a manual intermediate bump IS warranted (e.g. the latest patch
release carries an unrelated regression), the manual-bump PR
description MUST link the original Dependabot PR and explain why the
latest upstream version was not adopted. Closing the Dependabot PR
without that explanation is a process violation surfaced in the next
release retrospective.

### 5. No auto-merge

Every Dependabot PR is merged by a human, including security PRs, and
including patch bumps. `.github/dependabot.yml` proposes and never
adopts: a toolchain that updates itself in place takes a regressed or
compromised release with nobody having looked, which is not a posture
this market allows.

Earlier revisions of this section made auto-merge conditional on the
package appearing in an `auto-merge-allowlist` in `dependabot.yml`.
No such key existed, in that file or in any workflow, and Dependabot
has no such setting — the one clause here written as a machine-checkable
rule pointed at nothing. Green CI is a precondition for a maintainer to
merge, not a trigger that merges.

## Scope

This policy applies to:

- Dependabot security PRs against the `pulsar/framework` repo.
- Dependabot security PRs against bundled extension manifests
  (`extensions/*/composer.json`), whether or not they resolve against
  the framework's root lockfile. They do not:
  `extensions/auth/composer.json` declares `league/oauth2-server`,
  `web-auth/webauthn-lib` and `web-token/jwt-framework`, none of which
  appears in the root `composer.lock`. An earlier revision of this
  section scoped those out on exactly that ground, which left the
  framework's OAuth2, WebAuthn and JWT dependencies covered by no
  policy at all.

It does NOT apply to non-security version bumps (Dependabot's regular
update cadence), which follow the standard PR-size and review-
cadence policies described in ADR-0031.

### What enforces the scope

- `.github/dependabot.yml` carries one entry per manifest directory:
  `/`, `/vendor-bin/psalm`, `/extensions/auth`, npm `/`, and GitHub
  Actions. A directory absent from that list is proposed nothing, ever.
- `.github/workflows/dependency-audit.yml` audits the root tree, then
  every extension manifest declaring third-party requirements — it
  resolves each to a throwaway lock and runs `composer audit` against
  it — and fails the build when such a manifest has no `dependabot.yml`
  entry, so the list above cannot silently fall behind the tree.
- Both halves of the audit, PHP and JavaScript, assert through
  `tools/ci/assert-no-advisories.php`, which fails closed: a report that
  is missing, empty, unparseable or shaped differently than expected
  stops the build instead of reading as a clean tree.
- `roave/security-advisories` sits in `require-dev` of every manifest it
  has to protect, the root and `extensions/auth`. Composer ignores a
  dependency's `require-dev`, so the root entry never reached the
  extension: its `web-auth/webauthn-lib: ^5.0` overlapped roave's own
  `>=4.5,<5.3.5` vulnerable range and nothing objected. Resolution in
  that directory now refuses a known-vulnerable version outright, before
  any audit runs.

The same checks run locally as `composer security:audit` and
`composer security:audit:js`. For one extension:

```bash
composer update --working-dir=extensions/auth --no-install --no-audit
composer audit --working-dir=extensions/auth --locked --format=json > extensions/auth/audit-results.json
php tools/ci/assert-no-advisories.php extensions/auth/audit-results.json
```

## What is machine-enforced, and what is not

Enforced by CI today: which manifests are watched, which are audited,
and that the two sets agree — see "What enforces the scope" above.

Not enforced by any machine today, and honestly so:

- **The merge SLA itself.** No workflow reads PR labels or measures
  time-to-merge, so the table in §2 is upheld by review, not by a gate.
  Auditors should read it as policy, not as a control.
- **The `security-deferred` label** is applied by hand and carries no
  registered metadata.
- **A reminder before SLA expiry.** Nothing warns; the maintainer
  watches the queue.

Closing these means a scheduled workflow that queries open Dependabot
PRs, reads severity from the GHSA reference in the body, and fails or
comments on any PR past its window. Until that exists, no document in
this repository should describe the SLA as enforced.

## Cross-references

- ADR-0031 — Pull-request size limits (separate cap for non-security
  bumps; this policy supersedes for the security path).
- PCI-DSS 6.3.3 — timely security patches (regulator anchor).
- EU CRA Article 13 — vulnerability handling obligations.
