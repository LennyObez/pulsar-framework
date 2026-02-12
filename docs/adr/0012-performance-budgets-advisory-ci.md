# ADR-0012: Performance Budgets with Hybrid CI Enforcement

## Status

Accepted

## Context

Performance regressions are silent - unlike test failures, they do not produce errors. A commit that degrades container resolution from 80us to 500us will pass all tests and static analysis. Without measurement, regressions accumulate until they become noticeable in production.

Two approaches to CI performance enforcement exist:

1. **Hard gates.** Benchmark failures block the merge. Risk: flaky failures due to CI runner variance (noisy neighbors, CPU throttling, different hardware). Developers learn to distrust and disable the check.
2. **Advisory reporting.** Benchmarks run and produce reports, but never block merges. Risk: reports are ignored, regressions accumulate.

Pulsar needs a middle ground: defined performance contracts that are visible and tracked, but do not create false-positive merge blocks on shared CI infrastructure.

## Decision

Define explicit performance budgets for framework-critical operations. Use a **hybrid enforcement model**: benchmarks block merges when run-to-run signal is stable, and fall back to advisory mode when variance is high.

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

### Hybrid blocking strategy

- **Stable signal (low variance):** When run-to-run coefficient of variation is below a threshold (e.g., <10%), the benchmark is considered reliable. Regressions exceeding the budget are **merge-blocking**.
- **Unstable signal (high variance):** When CI runner variance is high (noisy neighbors, inconsistent hardware), the job remains **advisory** (`continue-on-error: true`). Results are uploaded as artifacts and PR summary for human review.
- **Current state:** The CI job starts as advisory. As benchmark infrastructure matures (dedicated runners, consistent hardware), individual budgets can be promoted to blocking by adjusting the variance threshold.

### Relative thresholds

Budgets use relative regression detection (e.g., "no more than 5% slower than baseline") rather than absolute wall-clock times. This accounts for CI runner variance while still detecting meaningful regressions.

## Consequences

### Positive

- **Visible performance contracts.** Every critical operation has a documented budget. Developers know the expected performance characteristics.
- **Graduated enforcement.** Stable benchmarks block; unstable ones advise. No false-positive merge blocks, no silent regressions on reliable metrics.
- **Historical tracking.** Artifact retention enables comparing benchmarks across PRs and releases.
- **Actionable reports.** Job summary integration surfaces results without requiring developers to download artifacts.

### Negative

- **Variance detection adds complexity.** The CI job must compute coefficient of variation and decide blocking vs. advisory per benchmark. This logic lives in the benchmark runner, not in the CI workflow.
- **Advisory budgets can be ignored.** Until a budget is promoted to blocking, developers can merge despite regressions. Mitigation: PR review process should include benchmark review for performance-sensitive changes.

### Neutral

- **Promotion is incremental.** Individual budgets can be promoted to blocking independently. Router benchmarks might become blocking while container factory benchmarks remain advisory.
