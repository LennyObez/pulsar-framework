# ADR-0072: A budget is the assertion that runs, not the JSON beside it

## Status

Accepted. **Supersedes [ADR-0012](0012-performance-budgets-advisory-ci.md)** (performance
budgets with hybrid CI enforcement). Every operative statement of ADR-0012's Decision was
false against the shipped CI; this record states what the enforcement is. Adds
`shell: bash` and a declared `continue-on-error` to one CI job that could not fail, and
corrects one budget and three file headers. Adds nothing to the `#[Api]` surface.

## Context

ADR-0012 described a hybrid model: machine-readable budget files, a coefficient-of-
variation calculation that promoted a benchmark from advisory to blocking once its signal
stabilised, relative thresholds against a stored baseline, and a `php-benchmark` job that
"starts as advisory". Read against the tree:

| ADR-0012 said                                                                                   | The tree says                                                                                                                                                                                                                                          |
| ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Budgets live in `budgets.fpm.json` / `budgets.persistent.json` "as machine-readable thresholds" | No code in this repository reads those files, or `performance-budgets.json` beside them. The budgets that fail a build are 77 `#[Assert]` attributes across 24 classes in `tests/Benchmark`, plus three byte counts hard-coded in `MemoryProfileBench` |
| "5 iterations, 1000 revolutions, and a 5% retry threshold"                                      | 5 and 1000, but `runner.retry_threshold` is **20** (`tools/php/phpbench.json:9`)                                                                                                                                                                       |
| CI job `php-benchmark`, advisory, `continue-on-error: true`                                     | `php-benchmark-tier-a`, named "Tier A: Performance Budgets (Hard Gate)", no `continue-on-error`, and `shell: bash` set precisely so the pipe into `tee` cannot swallow PHPBench's exit code                                                            |
| Blocking is decided by run-to-run coefficient of variation, below/above a threshold             | No CoV is computed anywhere. Nothing promotes or demotes a budget                                                                                                                                                                                      |
| "Budgets use relative regression detection … rather than absolute wall-clock times"             | Every `#[Assert]` is an absolute wall-clock budget. Relative detection exists, but in a different workflow with a different mechanism                                                                                                                  |
| Artifacts retained 14 days; results in the job summary                                          | True, and the only two statements that survive                                                                                                                                                                                                         |

The last row is worth dwelling on, because ADR-0012 was not wrong to want relative
detection — it just described something that was built elsewhere and differently.
`benchmark-regression.yml` measures the merge base and the pull request **in the same job
on the same runner**, then refuses anything more than 5% slower. It used to compare
against a committed `benchmark-baseline.json` that held one `_comment` key and never held
a measurement; every benchmark printed NEW, the table looked exactly like a comparison,
and the job went green having compared nothing, for the whole of the RC phase, under a
check named "Performance Regression Check". Populating that file would not have fixed it:
ops-per-second is a property of the machine that measured it, and a figure from a
maintainer's laptop compared on a shared runner is hardware noise with a threshold
attached.

So the shape ADR-0012 reached for — "relative, because absolute numbers do not survive
shared CI" — turned out to need _both halves measured on the same runner in the same job_,
which is a different design from the one it wrote down.

One thing the audit of ADR-0012 did not reach, found while checking it:
`php-benchmark-library` — the job that runs the standalone `benchmarks/` tree — pipes
PHPBench into `tee` and does **not** set `shell: bash`. GitHub's implicit default is
`bash -e {0}` without `pipefail`, so the step's exit status is `tee`'s, always 0. That
tree is deliberately not a merge gate, but "not a gate" was being delivered as "the shell
discards the result before anyone can look at it", which is not the same thing.

## Decision drivers

1. A performance budget that no machine reads is a comment. Three files described
   themselves as thresholds and were read by nothing; a reader had no way to tell which
   of the two copies was the one that could fail a build.
2. "Advisory" and "cannot fail" look identical in a green check mark and are not the same
   claim. [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)
   applies to a job that reports success because its shell threw the status away.
3. The hybrid promotion mechanism ADR-0012 designed was never built, and after four
   surfaces grew up around it, is not what anyone would build now.

## Decision

**A budget is the `#[Assert]` attribute on the subject that runs. Nothing else is a
budget.** The JSON files beside them are a reference index, labelled as such in their own
`description` field, and when the two disagree the attribute wins.

**Enforcement is decided per workflow and written in the workflow.** There is no variance
calculation, no promotion, and no demotion. Four surfaces, four postures:

| Surface                          | Trigger                                           | What it runs                                                                                                                                                   | Posture                                                                                          |
| -------------------------------- | ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `php-benchmark-tier-a` (ci.yml)  | every PR, after `php-quality`                     | manifest integrity check, `composer bench:ci` (77 absolute budgets), three memory-peak scenarios in isolated processes, the OPcache/JIT/preload profile matrix | **Blocking.** No `continue-on-error`; `shell: bash` so pipes propagate                           |
| `benchmark-regression.yml`       | PRs touching `src/`, `extensions/`, `benchmarks/` | merge base and head benchmarked in the same job on the same runner                                                                                             | **Blocking** at 5% slower                                                                        |
| `benchmark-nightly.yml` (Tier B) | 03:00 UTC and manual                              | the same budgets against real Redis and PostgreSQL                                                                                                             | **Advisory**, declared with `continue-on-error: true`                                            |
| `benchmark-rc-gate.yml` (Tier C) | `workflow_dispatch` before tagging an RC          | the same budgets plus `crypto.kdf` (Argon2id) against real backends; 90-day artifacts                                                                          | **Blocking for the run**; running it at all is a release-checklist obligation, not a branch rule |
| `php-benchmark-library` (ci.yml) | every PR, after `php-quality`                     | the standalone `benchmarks/` component tree                                                                                                                    | **Advisory**, now declared rather than accidental                                                |

### Budgets are absolute, and the environment is pinned so that they can be

`#[Assert('mode(variant.time.avg) < N')]` compares the mode of the per-iteration averages
against a wall-clock number. No percentile, no tolerance band, nothing that widens the
budget at runtime.

That only works because the measurement environment is fixed. `runner.php_config` in
`tools/php/phpbench.json` pins the child interpreter — OPcache on, JIT off, JIT buffer
zero, Xdebug off — so a budget means the same thing on CI as on the machine it was derived
on. Xdebug alone moved the kernel-boot subject by 2.2x. **Nothing in the Tier A step may
set ini flags**; doing so silently invalidates every budget in `tests/Benchmark`.

`runner.retry_threshold` is 20, and that is a decision rather than a slack setting.
PHPBench re-runs any iteration deviating more than the threshold from the variant mean, in
a `while (getRejectCount() > 0)` loop with no limit — phpbench 1.7 never calls
`SubjectMetadata::setRetryLimit()`. At 5, a variant that cannot settle does not fail, it
spins: one CMS subject retried more than ten times locally before landing at 3.3%, and the
CMS group alone did not finish inside twenty minutes. The threshold was never the gate.
Subjects whose spread is genuinely wider carry `#[RetryThreshold]` of their own —
`KernelBench::benchKernelBootAndHandle` takes 25, because a whole framework boot runs 9–15%
rstdev with the machine quiet.

### Relative detection is a separate gate, with its own reason for existing

`benchmark-regression.yml` is the only relative check, and its design constraint is that
**both halves must be measured by the same runner in the same job**. That costs a second
benchmark run, about two and a half minutes. It is the price of the check existing at all,
and the alternative — a committed baseline — was tried and produced a gate that compared
nothing for an entire release-candidate phase.

### The reference files stay, and say what they are

`tools/php/performance-budgets.json`, `budgets.fpm.json` and `budgets.persistent.json` are
kept, because a reader wanting the whole budget set in one place should not have to grep 24
classes. Each now opens with what it is: a reference table read by no code. `budgets.persistent.json`
additionally says that nothing measures its numbers at all — `tests/Benchmark` exercises the
FPM-shaped path only, so the persistent-runtime targets are a written expectation and never
a result.

Deleting them was considered. Generating them from the attributes was considered. Both are
recorded under Alternatives; neither is what this ADR does, and the residual risk — that
the index drifts from the attributes it indexes — is stated in Consequences rather than
claimed away.

### The manifest that shapes the request-class benchmarks is content-gated

`tools/php/bench-pipeline.manifest.php` declares the middleware stacks, storage backends and
required side-effects for each request class. Its SHA-256 is recorded in
`bench-pipeline.manifest.sha256` and compared by `composer bench:manifest:check` on every
Tier A run; editing the manifest without re-recording fails the build. That step used to
compute the digest, echo it, and compare it to nothing while the manifest's own header
described it as a gate. `BenchPipelineManifestGateTest` plants changes and watches it refuse
them.

## Alternatives considered

### Build the coefficient-of-variation promotion ADR-0012 designed

Rejected. It solves a problem the current arrangement does not have: Tier A runs on
in-memory drivers with a pinned interpreter and budgets set with margin, and its instability
was never the reason a budget failed. The genuinely noisy subjects are handled where the
noise is — `#[RetryThreshold]` per subject — rather than by a job-level statistic that would
have to decide, per run, whether the build may fail. A gate whose blocking-ness varies run
to run is a gate developers learn to re-run until it passes.

### Delete the three JSON files

The strongest argument for this record's honesty, and rejected on balance. They are a second
copy of a fact, which is the defect [ADR-0045](0045-a-control-status-is-observed-not-written.md)
and the generated ADR index both exist to remove. But `budgets.fpm.json` and
`budgets.persistent.json` carry the request-class and warm-path targets in one table a
performance reviewer actually reads, and `performance-budgets.json` is what `docs/performance.md`
links a newcomer to. Deleting them would move that content into prose, where it would drift
in exactly the same way with none of the structure. Labelling them was the smaller lie to
tell.

### Generate the JSON from the `#[Assert]` attributes

The right long-term answer, and not done here. It needs a stable mapping from budget id
(`container.instance_resolution`) to subject (`ContainerBench::benchInstanceResolution`), and
inventing that naming convention is a bigger decision than this record should make on the way
past. Until it exists, the index can disagree with the attributes and only a reader will
notice.

### Make `php-benchmark-library` blocking instead of declaring it advisory

Rejected. Those budgets were never calibrated on shared CI hardware, and promoting them on
the strength of "the shell was hiding them anyway" would convert an invisible non-gate into a
flaky one. `shell: bash` plus a declared `continue-on-error` makes a breach visible as a
failed-but-tolerated step, which is the signal the swallowed exit code destroyed.

## Consequences

### Positive

- **One place to look.** "What can fail this build?" is answered by the `#[Assert]`
  attributes and the four rows of the posture table, not by guessing which of three JSON
  files CI reads.
- **A breach in `benchmarks/` becomes visible.** The job could not fail and now reports.
- **The reference files cannot be mistaken for the gate.** Their first field says what they
  are, in the file, where someone editing them will read it.

### Negative

- **The index can still drift.** `performance-budgets.json` is maintained by hand against
  77 attributes, and nothing checks the two agree. This record found `kernel.dispatch` at
  500 µs in the table against 400 µs in the code and corrected it; the next divergence will
  be found the same way, by someone looking. Generating the file is the fix and is not done.
- **Persistent-runtime budgets are unmeasured and stay unmeasured.** Saying so in the file
  header is better than implying enforcement, but the framework still ships warm-path
  targets that no benchmark has ever tested.
- **Tier C depends on a human triggering it.** `workflow_dispatch` means "nothing enforces
  that it was run". The release checklist carries that obligation, and a checklist is not a
  gate.

### Neutral

- **No budget value changes** except `kernel.dispatch` in the reference table, which is
  corrected to the 400 µs the code has always asserted, and a `kernel.boot_and_dispatch` row
  added for the 30 ms subject the table never listed.
- **ADR-0012's Context survives intact.** Its framing — that performance regressions are
  silent, and that hard gates on shared runners risk being distrusted and disabled — is why
  the arrangement above looks the way it does.

## Security impact

None. No production code changed. For completeness, because Tier A carries a security-adjacent
step: the pipeline manifest integrity gate is unchanged by this record and remains the control
that makes a change to the benchmark contract visible in review.

## Performance impact

None on the framework. On CI: `php-benchmark-library` now propagates a non-zero PHPBench exit
into a step-level failure it tolerates, which changes reporting and not runtime.

## Migration / rollback plan

Nothing to adopt. Anyone adding a benchmark follows `docs/performance.md`, which now puts the
`#[Assert]` attribute first and the reference table second.

To roll back the CI change: remove the `defaults.run.shell` and `continue-on-error` from
`php-benchmark-library`. That restores a job that reports success unconditionally.

## Links

- [ADR-0012](0012-performance-budgets-advisory-ci.md) — the superseded decision; its Context
  is still the argument for measuring at all
- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md) —
  why a job that cannot fail is the thing being fixed here
- `docs/performance.md` — the tiers, the budgets, and how to add one
- `tools/php/phpbench.json` — iterations, revolutions, retry threshold, pinned interpreter
- `.github/workflows/benchmark-regression.yml` — the relative gate, and why its baseline is
  recorded on the runner
