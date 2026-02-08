# ADR-0001: CI Gates and ADR Discipline

## Status

Accepted

## Context

Pulsar is approaching 1.0.0 GA. The CI pipeline enforces code quality (PHPStan, Psalm, CS-Fixer), tests with coverage thresholds, and JS/TS linting. However, two categories of regression are not yet caught by CI:

1. **Cache warmup breakage.** The framework provides `optimize` and `optimize:clear` commands for production cache warming. If these commands fail, deployments break. There is no CI gate verifying they work.
2. **Undocumented architectural changes.** Core framework components (`Core/`, `Container/`, `Routing/`, `Http/`, `Extensibility/`, `Api/`, `Config/`) form the public contract. Changes to these paths can silently alter framework behavior without any record of the reasoning. Architecture Decision Records (ADRs) exist as a concept but are not enforced.

Without these gates, architectural drift and deployment failures can reach the main branch undetected.

## Decision

Add two new merge-blocking CI jobs and establish ADR governance:

### 1. Cache Warmup Smoke Test (`cache-warmup`)

A CI job that runs after `php-quality` passes. It installs the framework, then runs the cache warmup and clear commands to verify the pipeline works end-to-end. If `optimize:validate` is available (from a future plan), it uses the strict validation mode instead.

### 2. ADR Governance Check (`adr-check`)

A CI job that runs only on pull requests. It compares the changed files against a list of core architecture paths. If any core path is modified without an accompanying ADR (new or updated file in `docs/adr/`), the job fails with a clear error message listing the triggering files and instructions for resolution.

Core architecture paths that trigger the requirement:

- `src/Core/`
- `src/Container/`
- `src/Routing/`
- `src/Http/`
- `src/Extensibility/`
- `src/Api/`
- `src/Config/`

### 3. ADR Template and Process

A standardized template (`docs/adr/0000-template.md`) and sequential numbering convention (`NNNN-slug.md`). ADRs are immutable records — superseded decisions reference their replacement rather than being deleted.

## Consequences

### Positive

- **Deployment safety.** Cache warmup failures are caught before merge, preventing broken production deploys.
- **Architectural traceability.** Every significant core change has a documented rationale. Future maintainers can understand why decisions were made.
- **Low friction.** The ADR check only triggers for core paths, not for tests, docs, extensions, or peripheral code.
- **Fork-safe CI.** The ADR check uses explicit base SHA comparison, working correctly for PRs from forks.

### Negative

- **ADR overhead for small core changes.** Even trivial changes to core paths require an ADR. Mitigation: updating an existing ADR with a note is sufficient — a new ADR is not always required.
- **Additional CI time.** The cache warmup job adds a few minutes to the pipeline. Mitigated by running it in parallel with tests (both depend on `php-quality`).

### Neutral

- **ADR numbering is sequential, not semantic.** Numbers are assigned in order of creation. There is no meaning to the number beyond ordering.
- **The ADR directory was renamed from `docs/ADR/` to `docs/adr/`** for consistency with Unix conventions and case-sensitive filesystems.
