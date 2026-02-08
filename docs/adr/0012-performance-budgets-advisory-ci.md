# ADR-0012: Performance Budgets as Advisory CI Gates

## Status

Accepted

## Context

Performance regressions are silent — unlike test failures, they do not produce errors. A commit that degrades container resolution from 80us to 500us will pass all tests and static analysis. Without measurement, regressions accumulate until they become noticeable in production.

Two approaches to CI performance enforcement exist:

1. **Hard gates.** Benchmark failures block the merge. Risk: flaky failures due to CI runner variance (noisy neighbors, CPU throttling, different hardware). Developers learn to distrust and disable the check.
2. **Advisory reporting.** Benchmarks run and produce reports, but never block merges. Risk: reports are ignored, regressions accumulate.

Pulsar needs a middle ground: defined performance contracts that are visible and tracked, but do not create false-positive merge blocks on shared CI infrastructure.

## Decision

Define explicit performance budgets for framework-critical operations. Budgets are enforced by PHPBench benchmarks in CI, but the benchmark job is **advisory** (`continue-on-error: true`). Results are uploaded as artifacts and added to the GitHub Actions job summary for human review.

### Budget categories

| Category  | Threshold          | Operations                                                                                                                      |
| --------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| Sub-10us  | Near-zero overhead | Router static match (10 routes), middleware pipeline (5 layers), request/response creation                                      |
| Sub-100us | Fast               | Container resolution (instances, singletons), router static match (200 routes), validation (5 fields), authorization gate check |
| Sub-1ms   | Bounded            | Container factory resolution, full kernel dispatch cycle                                                                        |

### Enforcement model

- **Budget definitions** live in `tools/php/performance-budgets.json` as machine-readable thresholds.
- **PHPBench** runs benchmarks with 5 iterations, 1000 revolutions, and a 5% retry threshold.
- **CI job** (`php-benchmark`) runs after `php-quality`, produces benchmark text output and JSON results.
- **Artifacts** are retained for 14 days, enabling historical comparison across PRs.
- **Job summary** displays results directly in the GitHub Actions UI.
- **Non-blocking.** The job never fails the build. Regressions are flagged in the report for human review.

### Relative thresholds

Budgets use relative regression detection (e.g., "no more than 5% slower than baseline") rather than absolute wall-clock times. This accounts for CI runner variance while still detecting meaningful regressions.

## Consequences

### Positive

- **Visible performance contracts.** Every critical operation has a documented budget. Developers know the expected performance characteristics.
- **No false-positive blocks.** CI runner variance does not create flaky merge-blocking failures.
- **Historical tracking.** Artifact retention enables comparing benchmarks across PRs and releases.
- **Actionable reports.** Job summary integration surfaces results without requiring developers to download artifacts.

### Negative

- **Regressions can be ignored.** Advisory means a developer can merge despite a 10x regression if they choose not to read the report. Mitigation: PR review process should include benchmark review for performance-sensitive changes.
- **No automated rollback.** Unlike hard gates, advisory budgets do not prevent regressions from reaching the main branch.

### Neutral

- **Can be promoted to hard gates.** If CI infrastructure stabilizes (dedicated runners, consistent hardware), the `continue-on-error` flag can be removed to make budgets merge-blocking. The benchmark infrastructure is the same either way.
