# ADR-0042: Coverage and mutation are bounded by memory, and the gates say so

## Status

Accepted. Records a permanent reduction in what two quality gates verify, which
[ADR-0001](0001-ci-gates-and-adr-discipline.md) asks us to write down rather than
absorb quietly. Continues the argument of
[ADR-0041](0041-the-token-vault-takes-a-connection.md): a control that reports itself
green on the strength of never having run is worth less than no control at all.

## Context

The coverage job and the mutation job both died on CI, repeatedly, and both were read
as slow. Neither was.

`php-code-coverage` records, for every covered line, the identity of every test that
touched it:

```php
// SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData:84
$this->lineCoverage[$file][$k][] = $testCaseId;
```

That append is unconditional. There is no configuration switch, no report format that
skips it, and no PHPUnit setting that turns it off — per-test attribution is how the
library works. Memory therefore grows with the number of tests, without bound.

Measured on `ubuntu-latest`, which has 16 GB:

| Observation                                     | Value                                           |
| ----------------------------------------------- | ----------------------------------------------- |
| Tests executed before the process was killed    | 13,457 of the Unit suite's 42,588               |
| Wall time to that point                         | ~8 minutes                                      |
| Exit code Infection reported                    | `-1` (terminated by signal, not a failing test) |
| Implied cost                                    | ~1.1 MB per test                                |
| Implied requirement, Unit suite (42,588)        | > 45 GB                                         |
| Implied requirement, whole config (44,544 then) | > 48 GB                                         |

Those two suite sizes are what they were on the commit that measured this. Every count
in this record that describes the tree **now** is marked as measured at rc.12, because a
number in prose rots and the difference between a historical observation and a live one is
the whole reason this table was inconsistent with itself.

The exit code is the part that misled us for several runs. Infection reports a signal
kill as `-1` and prints "Project tests must be in a passing state", which reads as a
failing test. No test was failing. The kernel was killing the process.

Time was never the constraint. At 0.036 s per test the whole suite is about 26 minutes,
comfortably inside a job. Sharding the run sixteen ways — the previous arrangement —
addressed a problem that did not exist while leaving the one that did.

Two dead ends are worth recording so they are not re-explored:

- **Xdebug instead of PCOV.** Xdebug is the only driver that emits branch and condition
  data, and it costs 0.87 s per test on this runner: 10.75 hours for the full suite, past
  GitHub's own six-hour ceiling for a job. No arrangement of one job finishes it. What one
  job can finish is a slice, which is what `coverage-nightly.yml` measures.
- **Reusing one instrumented run for both gates.** Infection consumes per-test coverage
  in PHPUnit's XML format. That is the same data that will not fit in memory, now
  written to disk; the artifact would run to several gigabytes.

## Decision

**Coverage runs in one job whose process is recycled.** `php-coverage` has no matrix and
no fan-out. Inside it, `tools/ci/partition-tests.php` cuts PHPUnit's own test listing
into ten contiguous parts — about 4,675 tests each at rc.12, roughly 5 GB apiece — and the
job runs them in sequence. `tools/ci/merge-clover.php` then merges the ten Clover reports at
line level: a line is covered if any part covered it, and file and project metrics are
recomputed from the merged lines rather than summed from the parts, because summing
would count a line covered by two parts twice.

The list comes from `--list-tests-xml`, never from a hand-maintained set of paths, so a
directory added to `phpunit.xml` enters the parts by itself. This matters: a partitioner
fed by its own path list is the same class of gap that once left eight extension suites
unexecuted.

**The threshold gate reports an unmeasured dimension as unmeasured.** PCOV records lines
and methods and has no notion of branches, so Conditions arrives as 0/0. Printing
"0.00%" for it would read as a collapse in quality rather than as an absent driver, so
`tools/ci/assert-coverage-threshold.php` prints `not measured` and enforces statements
and methods only.

**Mutation testing is scoped to the security-critical core.** `infection.json5` mutates
`src/Auth`, `src/Security` and `src/Audit`, and its initial run executes only the tests that
cover them — 3,712, measured at rc.12 — through a PHPUnit configuration of its own at
`tools/php/mutation/phpunit.xml`. Covered MSI is enforced at 90; plain MSI is not,
because it counts mutants no test reaches and the test scope is narrowed on purpose.

That separate configuration file is a copy, and a copy rots. `MutationConfigTest`
compares the two on every setting they are meant to share — strictness flags, runner
extensions, the `<php>` block, the bootstrap target — so a flag added to one and not the
other fails a test instead of quietly loosening the rules mutation testing runs under.

Three narrower mechanisms were tried first and rejected on evidence:

- A second `<testsuite>` naming those paths makes `composer test` run all of them twice.
- Carving them out of the `Unit` suite drops them from `composer test:all`, which selects
  that suite by name — a silent loss of all 3,712 of them.
- A `--filter` regex has to survive JSON5 unescaping and then per-platform argument
  escaping. On Windows the anchor arrived as `"^^"` and the run matched nothing. A gate
  should not rest on that.

## Consequences

The condition and branch dimension is **not measured on a pull request**, and will not be
until PCOV grows branch support. Statement and method coverage are enforced as before,
over the whole suite.

It is measured nightly over part of the tree. `coverage-nightly.yml` is a single job
with no matrix, and that constraint decides its scope: Xdebug needs 10.75 hours for the
full suite against GitHub's six-hour ceiling for one job, and splitting the work changes
each piece without changing the total. So the nightly measures the slice a single job can
reach — `src/Auth`, `src/Security` and `src/Audit`, through the same
`tools/php/mutation/phpunit.xml` that scopes mutation testing, so both figures describe
the same code. Branch coverage for the other 2,247 files under `src/` (rc.12) is measured
nowhere, and will not be until PCOV learns branches.

That figure is reported, not gated, and the distinction is deliberate. Branch coverage
has never been measured on this suite. A floor set before the first measurement would be
a number someone picked, not a standard anything met, and a gate nobody can justify is
the kind that gets lowered the first time it fails. `assert-coverage-threshold.php`
therefore takes `--report-only=Conditions`, which prints the figure and refuses to let it
decide the outcome — while leaving statements and methods gated exactly as they are on a
pull request. Setting the floor is the follow-up to the first green nightly run, and it
is a decision that belongs to whoever reads that number.

Mutation testing covers 495 of the 2,742 files under `src/`, measured at rc.12. Everything
outside those three trees has no mutation gate. This is a real reduction, and it is the honest
one: before this change the gate failed on every run, which enforced nothing while
appearing to enforce something.

Per-class `<metrics>` blocks are absent from the merged Clover report. Clover records
class metrics but never says which lines belong to which class, so they cannot be
recomputed from the parts, and carrying one part's numbers forward would publish a figure
that is wrong for the merged run. File-level and project-level metrics — everything the
gate reads, and everything a reader would total by hand — are exact.

The coverage job now depends on two scripts of our own. Both are covered:
`tests/Unit/Tooling/TestPartitionTest.php` asserts that every test lands in exactly one
part, and `tests/Unit/Tooling/CloverMergeTest.php` asserts that a line covered by two
parts is counted once. A slip in either would move the enforced figure without moving a
single line of covered code, so neither ships untested.

If the suite keeps growing, `COVERAGE_PARTS` in `ci.yml` is the dial. At the measured
1.1 MB per test, keep parts under about 5,000 tests. At rc.12 the whole configuration lists
46,745 tests and the Unit suite 44,510, so ten parts sit at about 4,675 — one more part is
due at roughly 50,000.
