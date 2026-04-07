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

If the repo does not provide `scripts/qa` yet, use:

```bash
composer qa
pnpm lint
pnpm format:check
pnpm test
```

> The goal is to keep local workflows and CI identical to avoid “works on my machine”.

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
- Qodana inspections
- PHP formatting:
- PHP-CS-Fixer
- JS/TS:
- TypeScript typecheck (if configured)
- ESLint (flat config)
- Prettier
- Security checks:
- dependency advisories
- secret scanning (never commit secrets)

If a tool requires baseline files (e.g., Qodana), keep baselines small and documented.

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
`1.0.0` milestone). They close audit finding F28.5 by making the
ADR check, quality gate, and test suite into hard merge gates rather
than CI signal that can be force-pushed past.

Required GitHub branch protection rules for `main`:

- **Require pull request before merging** with at least 1 approving
  review from a CODEOWNERS-listed reviewer.
- **Require status checks to pass** — selected checks:
  - `php-quality` (CS, PHPStan, Psalm, boundaries, version
    consistency)
  - `php-tests` (unit + integration + E2E suites)
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
number, date, applying maintainer, and justification (F28.3).

Without these protections, the CI workflow alone is signal — any
admin can force-push to bypass it. The gates only become controls
once branch protection enforces them.

Thanks for helping build Pulsar.
