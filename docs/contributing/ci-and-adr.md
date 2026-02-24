# CI Pipeline and ADR Governance

## CI Pipeline Overview

The CI workflow (`.github/workflows/ci.yml`) runs on every push and pull request. It consists of the following jobs:

### Merge-blocking jobs

| Job            | Description                                           | Dependencies   |
| -------------- | ----------------------------------------------------- | -------------- |
| `php-quality`  | CS-Fixer check, PHPStan, Psalm, dependency audit      | None           |
| `php-tests`    | Unit, Integration, E2E tests with coverage threshold  | `php-quality`  |
| `cache-warmup` | Verifies the framework cache warmup pipeline works    | `php-quality`  |
| `adr-check`    | Enforces ADR governance for core architecture changes | None (PR only) |
| `js`           | ESLint, Prettier, TypeScript check, Vitest            | None           |

### Advisory jobs

| Job             | Description                               | Dependencies  |
| --------------- | ----------------------------------------- | ------------- |
| `php-benchmark` | PHPBench microbenchmarks + profile matrix | `php-quality` |

Advisory jobs currently use `continue-on-error: true`. Individual benchmarks may be promoted to merge-blocking as signal stability improves (see ADR-0012).

### Job dependency graph

```
push/PR
├── php-quality
│   ├── php-tests
│   ├── php-benchmark (advisory)
│   └── cache-warmup
├── adr-check (PR only)
└── js
```

## Running Checks Locally

Run the full PHP quality gate:

```bash
composer qa
```

This runs CS-Fixer check, PHPStan, Psalm, and tests in sequence.

Individual tools:

```bash
composer cs:fix          # Auto-fix code style (PER-CS2.0)
composer phpstan         # PHPStan level max
composer psalm           # Psalm error level 1
composer test            # PHPUnit test suite
```

If PHPStan runs out of memory:

```bash
php -d memory_limit=512M vendor/bin/phpstan analyse -c tools/php/phpstan.neon
```

JS/TS checks:

```bash
pnpm lint                # ESLint
pnpm format:fix          # Prettier auto-fix (MD, YAML, JSON, JS/TS, HTML, CSS)
pnpm format:check        # Prettier check (what CI runs)
pnpm typecheck           # TypeScript --noEmit
pnpm test                # Vitest
```

## ADR Governance

### What is an ADR?

An Architecture Decision Record (ADR) is a short document that captures a significant architectural decision, its context, and its consequences. ADRs are immutable records - once accepted, they are not deleted. If a decision is reversed, a new ADR supersedes the original.

### When to write an ADR

An ADR is **required by CI** when a pull request modifies any of these core architecture paths:

- `src/Core/`
- `src/Container/`
- `src/Routing/`
- `src/Http/`
- `src/Extensibility/`
- `src/Api/`
- `src/Config/`

For trivial changes to core paths (typo fixes, import reordering, doc comment updates) that do not alter behavior, API, or architecture, you have two options:

1. Update an existing ADR with a brief note - a new ADR is not always required.
2. Label the PR `adr-exempt` to skip the check. This requires maintainer approval and is visible in the PR history.

ADRs are also encouraged (but not CI-enforced) for significant changes to extensions, new subsystems, or changes to the CI/build pipeline itself.

### How to write an ADR

1. Copy the template: `docs/adr/0000-template.md`
2. Name the file: `docs/adr/NNNN-short-slug.md` where `NNNN` is the next sequential number
3. Fill in all sections: Status, Context, Decision Drivers, Decision, Alternatives Considered, Consequences, Security Impact, Performance Impact, Migration/Rollback Plan, Links
4. Set the status to `Proposed` for the PR. It becomes `Accepted` when merged.

### ADR numbering

Numbers are assigned sequentially. Check existing files in `docs/adr/` to find the next available number. There is no semantic meaning to the number - it only provides chronological ordering.

### ADR statuses

| Status                 | Meaning                                 |
| ---------------------- | --------------------------------------- |
| Proposed               | Under review in a PR                    |
| Accepted               | Merged and in effect                    |
| Deprecated             | No longer relevant but kept for history |
| Superseded by ADR-NNNN | Replaced by a newer decision            |

### How the CI check works

The `adr-check` job runs only on pull requests. It:

1. Computes the diff between the PR head and the base branch
2. Scans the diff for changes to core architecture paths
3. If core paths are modified, checks whether the PR also includes a new or modified ADR file in `docs/adr/`
4. Fails with a descriptive error message if core paths changed without an ADR

The check is fork-safe - it uses the base SHA provided by GitHub Actions rather than relying on branch names.
