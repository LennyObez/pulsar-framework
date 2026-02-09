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

Thanks for helping build Pulsar.
