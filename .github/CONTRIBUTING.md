# Contributing

Thanks for contributing to Pulsar.

Pulsar targets security-critical and regulated applications. Contributions must meet strict standards for correctness,
security, performance, maintainability, and documentation quality.

## Quick rules

- No placeholders. No TODO. No “example-only” code in production paths.
- PHP: `declare(strict_types=1);` in **PHP files only**.
- JS: prefer TypeScript; no `any` without explicit justification.
- HTML: HTML5 only; accessibility-first (WCAG-driven patterns).
- Docs: English only.
- No “certified/compliant” claims without evidence. Use “maps to controls” / “supports control requirements”.

## Development setup

### Prerequisites

- PHP 8.5+
- Composer (latest stable)
- Node.js (LTS recommended) + pnpm (via Corepack)
- Git

### Install

```bash
git clone https://github.com/LennyObez/pulsar-framework.git
cd pulsar-framework

composer install
corepack enable
pnpm install
```

### One-command quality gate

Run the same gate as CI:

```bash
./scripts/qa
```

`scripts/qa` is a thin wrapper: it calls `composer qa` for the PHP gate and adds the
four `pnpm` checks, which live in `package.json` and so cannot be `composer` entries.
`composer qa` on its own covers everything except those four.

Two of the entries need a tool that is not a Composer dependency:

```bash
pipx install "semgrep==1.155.0"   # composer security:lint
```

The version is pinned deliberately — Semgrep's PHP frontend parses a different set of
files in each release, and `tools/security/semgrep-baseline.json` records which files
it cannot parse under this one. If Semgrep is missing, `composer security:lint` exits
2 rather than passing: a gate whose tool is absent must not look like a gate that
found nothing.

> Local and CI cannot drift. `composer qa` is the single definition of the PHP gate,
> and `composer qa:parity` — itself the first entry of `qa`, and a required CI step —
> fails the build if `.github/workflows/ci.yml` does not run every entry in it. CI is
> allowed to enforce _more_ than `qa` (PR size, ADR governance, dependency audit,
> coverage, mutation, benchmarks: things needing pull-request context, network access
> or an hour of runner time). It is not allowed to enforce less.

## Repository structure

See `docs/repository-structure.md` for the canonical layout.

High-level responsibilities:

- `src/` Pulsar core (small, stable, hot paths)
- `extensions/` first-party extensions using the public extension API
- `modules/` example apps or built-in modules (HMVC boundaries)
- `tools/` configuration for analyzers and test runners
- `scripts/` stable entrypoints for developers and CI

## Coding standards

### PHP

- Every PHP file must start with:

```php
<?php
declare(strict_types=1);
```

- Prefer explicit dependencies (constructor injection).
- Avoid hidden global state and service locators.
- Public APIs must be typed and documented.
- Exceptions must be typed; no silent failures.
- If a change impacts a hot path, include a benchmark note and measure it.

### TypeScript / JavaScript

- Prefer TypeScript for all new JS code.
- Typed linting is preferred; avoid `any`.
- Keep Node tooling optional for runtime: Pulsar core must remain deployable without Node.

### HTML / UI

- HTML5 semantic markup only.
- Accessibility is mandatory:
- keyboard support
- correct labels and focus behavior
- minimal, correct ARIA usage

## Testing

- Add tests for any new behavior.
- Cover edge cases and failure paths (especially security-sensitive behavior).
- Prefer deterministic tests:
- avoid real timeouts where possible
- control randomness with seeded generators
- Use integration tests when behavior depends on multiple components.

## Static analysis & formatting (mandatory)

Before opening a PR, the full gate must pass:

- PHP static analysis:
- PHPStan
- Psalm
- Boundary enforcement (Deptrac, plus the `#[Api]`/`#[Internal]` targeting check)
- PHP formatting:
- PHP-CS-Fixer
- JS/TS:
- TypeScript typecheck (if configured)
- ESLint (flat config)
- Prettier
- Security checks:
- dependency advisories
- secret scanning (never commit secrets)

Findings are fixed, never silenced. Three things are sometimes mistaken for a fix and
are not one, and the difference between them matters enough to spell out — the
repository uses the third deliberately in four places and rejects the first two.

**Not accepted.** An analyser exclude, a `@phpstan-ignore` / `@psalm-suppress` with no
reason, a rule deleted from a config, or a threshold raised until the finding falls
below it. Each removes the finding from view while leaving the code exactly as it was,
and leaves nothing behind that says a decision was made.

**Accepted at the call site, with a reason.** A suppression naming the specific rule and
stating why the finding is wrong _about this code_ — for example the `nosemgrep` on
`AdminGateway::export()`, which records that the matched receiver is a feature handler
rather than a `Statement` and that the row count is bounded. The reason sits next to the
code it excuses, so the next reader can check the claim and delete the suppression when
it stops being true. A suppression with no reason is the first category wearing a
disguise.

**Accepted as a recorded floor.** A ratchet baseline —
`tools/php/phpstan-baseline.neon`, `tools/php/substitutability-baseline.json`,
`tools/security/semgrep-baseline.json` — enumerates debt that predates the gate so the
gate can reject the next occurrence. It differs from silencing in three ways that are
load-bearing: every entry is written down and readable in review, the count only ever
moves down, and adding to it is a visible diff a reviewer must approve. Regenerating a
baseline to clear a finding your own change introduced is silencing, and reviewers
should treat a grown baseline as a change to the code, not to a config file.

## Adding a gate (mandatory: it must be watched refusing)

If your change adds anything that can block a merge or a release — a script under
`tools/ci/` or `scripts/`, a leaf of `composer qa`, a workflow step that can fail, a
custom PHPStan rule or Psalm hook, a threshold, a ratchet — it needs a **negative test**
before it is a gate.

> A check that has never been observed to fail is indistinguishable from no check.

That is [ADR-0060](../docs/adr/0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md),
and it is not a style preference. An audit of this repository found **nine gates that
could not fail**, each discovered separately, each green the whole time: a script that
exited before checking anything, a Semgrep ruleset no workflow ran, a benchmark asserting
a 500-microsecond budget over zero work inside a job named "Hard Gate", a ratchet whose
`git grep` skipped the untracked files it existed to catch. None was caught by review;
four of them passed review more than once.

A gate passing proves nothing on its own, because a passing gate is consistent with two
worlds — the tree is clean, or the gate is inert — and green does not tell them apart.

### What a negative test is

1. It **plants the defect** the gate exists to catch. Not a healthy repository the gate
   is happy about; that distinguishes nothing.
2. It **runs the real gate**, with the config `composer qa` and CI actually name — as a
   subprocess where the gate is a script, because the exit code is the whole product.
3. It **asserts the refusal**, and the assertion message names _what would have shipped_
   had the gate stayed silent.

Fixtures go in a temp tree, never in the working tree: a fixture committed under `tests/`
lands inside the scan paths of the very gates the suite must keep green.
`tests/Unit/Tooling/Support/PlantsDefectsForGates.php` has the machinery, and
`tests/Unit/Tooling/TestYieldGateTest.php` is the worked example.

### Declare it

```php
#[GuardsGate(
    gate: 'tools/ci/assert-my-new-thing.php',
    plants: 'a report whose executed count is under the floor',
)]
final class MyNewThingGateTest extends TestCase
```

The declaration lives on the test, never in a list beside it — a list beside it is a
second definition of the same fact, and the two drift.

`tests/Unit/Tooling/PipelineGateCoverageTest.php` derives the gate list from
`composer.json`, the workflow files and the filesystem, so **your gate is in scope the
moment you write it** and the build stays red until it is declared or exempted. Ratchets
under `tests/Unit/Integrity/` are enumerated the same way by
`tests/Unit/Integrity/GateNegativeCoverageTest.php`.

### If it genuinely cannot be tested

Add an entry to `EXEMPT` with a reason a reviewer can check, and raise `EXEMPT_CEILING`
in the same commit. Use `UNFALSIFIABLE` when no refusal can be observed at all (say what
guards the gate instead); use `PENDING` when the test can be written and has not been
(say what writing it would take). An unfalsifiable gate is a finding, not a formality.

## Performance & regression prevention

If your change affects:

- kernel boot
- routing dispatch
- container resolution
- middleware pipeline
- serialization/validation

…include:

- a brief performance note in the PR
- benchmark results (before/after) if available
- justification for any regression (must be accepted explicitly)

Pulsar aims for measurable performance leadership; performance is a product feature.

## Documentation requirements

Any feature change requires:

- updating relevant docs under `docs/`
- adding usage notes if it impacts API or behavior
- documenting security implications (even if “none”)

Docs must stay aligned with real behavior.

## Branching and commits

### Branch naming

- `feat/<topic>`
- `fix/<topic>`
- `docs/<topic>`
- `perf/<topic>`
- `security/<topic>`
- `refactor/<topic>`

### Commit messages (Conventional Commits)

Format:

```
<type>(<scope>): <imperative summary>
```

Types: `feat`, `fix`, `docs`, `perf`, `refactor`, `test`, `ci`, `build`, `chore`, `security`

Scopes examples: `core`, `http`, `router`, `container`, `console`, `dx`, `ext`, `security`, `obs`, `docs`, `tooling`, `ci`

Examples:

- `feat(ext): add extension manifest validator`
- `perf(router): reduce regex allocations in matcher`
- `security(session): rotate session id on privilege change`
- `docs(architecture): document boot pipeline stages`

## Pull requests

A PR must include:

- problem statement (what and why)
- what changed (key points)
- security impact (explicitly “none” if applicable)
- performance impact (explicitly “none” if applicable)
- tests added/updated
- docs updated

### PR checklist

- [ ] Tests added/updated
- [ ] `./scripts/qa` (or equivalent) passes locally
- [ ] No new placeholders / TODOs
- [ ] No “certified/compliant” claims without evidence
- [ ] Docs updated
- [ ] Performance note included if relevant

## Security issues

Do **not** open public issues for vulnerabilities.
Follow `SECURITY.md` to report privately.

## Branch protection requirements (maintainers)

The CI gates documented above are advisory until the repository's
branch protection rules enforce them. For this framework's banking /
healthcare / legal positioning, the following protections MUST be
applied to `main` (and to any release branch tracking an `rc.x` /
`1.0.0` milestone). They turn the ADR check, quality gate, and test
suite into hard merge gates rather than CI signal that can be
force-pushed past.

Required GitHub branch protection rules for `main`:

- **Require pull request before merging** with at least 1 approving
  review from a CODEOWNERS-listed reviewer.
- **Require status checks to pass** — selected checks:
  - `php-quality` (CS, PHPStan, Psalm, boundaries, version
    consistency, local/CI gate parity)
  - `php-tests` (unit + integration + E2E suites)
  - `security-semgrep` (Pulsar ruleset, gating at WARNING)
  - `compliance-report` (every claimed control observed in a booted
    deployment)
  - `cache-warmup` (artefact-pipeline smoke check)
  - `adr-check` (governance, see ADR-0001)
  - `pr-size` (size cap per ADR-0031, when CI workflow lands)
- **Require branches to be up to date** before merging.
- **Require signed commits** (matches the project rule that all
  commits are GPG-signed).
- **Restrict who can push to matching branches** — administrators
  only, no force-push, no deletion.
- **Require linear history** (squash-merge or rebase only) so
  `git bisect` granularity is preserved per ADR-0031.

The `adr-exempt` label MUST be restricted to maintainers via
repo-level label settings (not editable by contributors). Each
exemption MUST be recorded in `docs/adr/exemptions.md` with the PR
number, date, applying maintainer, and justification.

Without these protections, the CI workflow alone is signal — any
admin can force-push to bypass it. The gates only become controls
once branch protection enforces them.

Thanks for helping build Pulsar.
