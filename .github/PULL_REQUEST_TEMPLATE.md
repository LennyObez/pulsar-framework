<!--
Thank you for contributing to Pulsar Framework.
Fill out every applicable section. Leave sections blank only when they do not apply.
-->

## Summary

<!-- One or two sentences describing the change. -->

## Type of change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to change)
- [ ] Performance improvement
- [ ] Refactor (no functional change)
- [ ] Documentation only
- [ ] Security fix (coordinate with maintainers via private channel first)
- [ ] Test / build / CI change
- [ ] Dependency update

## Scope

<!-- Name the crate(s) touched: pulsar-kernel, pulsar-http, pulsar-engine, etc. -->

## Architectural impact

- [ ] Public API changed — linked ADR: `docs/adr/NNNN-...md`
- [ ] Kernel invariants affected — TLA+ spec updated
- [ ] Creusot proofs added or revised
- [ ] Threat model entry added or revised
- [ ] Performance baseline updated — `docs/perf/benchmarks-baseline.json`

## Quality gates (run locally)

- [ ] `cargo fmt --check` passes
- [ ] `cargo clippy --all-targets --all-features -- -D warnings` passes
- [ ] `cargo check --all-targets --all-features` passes
- [ ] `cargo nextest run --all-features` passes
- [ ] `cargo llvm-cov`: line, branch, condition coverage at 100% on touched modules
- [ ] `cargo mutants --workspace`: mutation score ≥ 95% on touched modules
- [ ] `cargo audit --deny warnings` passes
- [ ] `cargo deny check` passes
- [ ] `cargo machete`: no unused dependencies introduced
- [ ] `criterion` benchmarks: no regression versus baseline (if perf-sensitive)
- [ ] Fuzz targets extended or added (if parser / untrusted-input boundary)

## Documentation

- [ ] Public items documented (`#[doc]`)
- [ ] `docs/api-surface.md` regenerated if public API changed
- [ ] `CHANGELOG.md` updated under `[Unreleased]`
- [ ] Book chapter updated (`docs/book/`) if user-facing
- [ ] ADR linked above

## Testing notes

<!-- Describe how the change was tested. Include commands, test data, edge cases considered. -->

## Security notes

<!-- If touching crypto, auth, audit, session, routing, middleware, or any untrusted-input parsing boundary, describe the threat model impact. -->

## Related issues / ADRs / specs

<!-- Closes #123, Relates #456, ADR-0012, spec/Router.tla v3 -->

## Migration notes

<!-- Instructions for users upgrading past this change. Leave blank for non-breaking changes. -->
