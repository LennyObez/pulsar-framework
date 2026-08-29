# ADR-0060: A check that has never been observed to fail is indistinguishable from no check

## Status

Accepted. Governs every gate that can block a merge or a release, which
[ADR-0001](0001-ci-gates-and-adr-discipline.md) established and which this ADR does not
change. It adds one obligation to each of them.

Generalises [ADR-0041](0041-the-token-vault-takes-a-connection.md)'s thesis — that a
control reporting itself implemented on the strength of code existing has reported
nothing — from the code to the gates that guard it.

## Context

An audit of this repository found **nine gates that could not fail**. They were found
separately, on different days, by people looking for different things. Each one was
green. Each one had been recorded as implemented, in a checklist or a workflow name or a
docblock, on the strength of the code existing.

1. `scripts/check_compliance_claims.php` read `docs/compliance-matrix.md`, a file that
   does not exist, and exited 1 unconditionally without checking anything.
2. `composer qa` was cited in CONTRIBUTING.md as the mandatory gate and was executed by
   **no workflow**. Its only mention anywhere outside composer.json was that sentence.
3. `semgrep` appeared **zero times** under `.github/`. The ruleset's own header said
   `NOTHING IN CI RUNS THIS FILE`.
4. `DriverDispatchRatchetTest` searched with `git grep`, which skips untracked files. It
   was green on a branch it would have refused the moment that branch was committed.
5. `benchKernelBootAndHandle()` asserted under 500 microseconds, inside a job named
   "Tier A: Performance Budgets (Hard Gate)", and **executed zero wirings**. The
   benchmark got faster the more broken the kernel was.
6. The migration contract tests survived mutation of the exact repairs they guarded:
   reverting every wide-text column and deleting every collation left them green.
7. `ArgumentResolverChainTest` asserted a security property **in prose** — that an
   application resolver cannot displace a framework one — without testing it. The
   property was false.
8. `SandboxReachAnalyzerTest` passed **vacuously** on any allow-list entry naming a type
   that does not exist.
9. `OutcomeSealTest` passed against every attack a reviewer executed, because it enforced
   the **spelling** of its rule rather than the rule.

A tenth was found while enumerating the other nine. `ci.yml`'s step "Verify pipeline
manifest integrity", in the hard-gate job, computes the sha256 of
`tools/php/bench-pipeline.manifest.php` and writes it to `$GITHUB_ENV` for the job
summary. No expected value exists anywhere in the repository. Replace the manifest
wholesale and the step prints a different hash and passes. Its name asserts something its
body does not check.

### What the nine have in common

Not a bug. Nine different bugs, in five languages, across scripts, workflows, benchmarks
and tests. The common property is that **nobody had ever watched any of them refuse.**

A gate is a claim about the future: _if this defect appears, the build stops_. The claim
is never tested by the gate passing. A gate passing is consistent with two worlds — the
tree is clean, or the gate is inert — and a green run does not distinguish them. Every
one of the nine sat in the second world, sometimes for months, emitting exactly the
signal the first world emits.

This is not a testing gap. It is a **category error about what evidence a green check
is**, and it is the same error ADR-0041 removed from the compliance vocabulary: there,
a control was "implemented" because a class existed; here, a check is "enforced" because
a script exists. In both cases the artefact was mistaken for the behaviour.

### Why more review would not have caught it

Every one of the nine passed review. Four passed several. Reading a gate tells you what
it intends; it does not tell you whether it works, and the failure modes involved —
`git grep` skipping untracked files, `tee` swallowing an exit code before `pipefail` was
set, a benchmark whose `setUp()` silently wired nothing — are precisely the ones that
survive reading and die on execution.

Three prior attempts in this repository to fix this class of problem by being more
careful all failed the same way, and are recorded here so the fourth is not proposed
again: a hand-maintained list of gates, a migration validator with a hardcoded provider
list, and an allow-list test that passed vacuously on entries naming types that did not
exist. Each was correct when written and silently wrong later. **A list that must be
remembered is a list that will be forgotten**, and its failure direction is always
"everything is covered".

## Decision

**Every gate carries a negative test: one that plants the defect the gate exists to
catch, runs the real gate, and asserts it refuses.**

Four things follow, and each is load-bearing.

### 1. The test constructs the unhealthy case

Running a gate against the healthy repository and asserting exit 0 distinguishes nothing
— it is the green run whose ambiguity started this. `DeptracConfigTest::deptrac_analyse_passes()`
and `::boundary_custom_script_passes()` are exactly that shape and are explicitly **not**
negative coverage of the boundary gates, despite sitting where a reviewer would look for
it.

### 2. The assertion message names what would have shipped

Not "expected 1, got 0". The message states the consequence of the gate having stayed
silent — the extension that would have installed unexecuted, the framework that cannot
start behind two green checkmarks, the array parameter that may now ship with no value
type. A negative test is the only place that consequence is ever written down, because
the gate itself only ever prints a refusal nobody has seen.

### 3. The claim is machine-readable and lives on the test

```php
#[GuardsGate(gate: 'tools/ci/assert-test-yield.php', plants: '…')]
```

On the test, never in a list beside it. A list beside it is a second definition of the
same fact and the two drift — which is what
[`CompositionRootsAuthorityTest`](../../tests/Unit/Integrity/CompositionRootsAuthorityTest.php)
exists to prevent one level down, after three checkers each carried their own copy of the
composition-root list and a `Pulsar\Core\Boot` class passed the static gate and was
refused at runtime.

This follows the `# qa-gate:` annotation precedent: the claim sits where the reviewer is
already looking.

### 4. The gate list is derived, never typed

`GateInventory` reads declarations the repository already maintains for other reasons, so
the list cannot be forgotten without also breaking the thing it is for:

- the leaves of `composer qa`, expanded through `@` references;
- first-party scripts invoked by a workflow step that can fail the build;
- checkers sitting beside those scripts, found by whether they can exit non-zero;
- composer and pnpm targets a blocking step runs, resolved against `composer.json` and
  `package.json` so that pnpm's own subcommands are not mistaken for gates;
- the custom PHPStan rules and Psalm hooks, walked out of their directories, because a
  passing analyzer says the configured rules found nothing rather than that they are
  still registered and still firing;
- the suppression baselines something ratchets, read from
  `tools/php/analysis-baseline-ceiling.json` rather than from a glob, because that file is
  already the declaration of which baselines are ratcheted and which are known gaps.

The directories scanned are not configured. They are wherever the first sources found
something, so a gate added next month in a directory nobody has thought of is discovered
through its own invocation, and its neighbours are swept in with it.

Deriving is not free of judgement, and the judgement calls are the interesting part —
each of the ones below was wrong first, and was caught by the enumeration's own negative
test or by watching it go red:

- `canRefuse()` first looked for a digit immediately after `exit(`, which classified
  `scripts/wiring_check.php` — ending `exit($unwiredCount > 0 ? 1 : 0)` — as a library and
  dropped a real gate out of the inventory silently.
- The step walk first keyed on `- name:`, and ci.yml's `js` job is four bare `- run:`
  steps, so all four JS gates were invisible.
- The refusal detector first accepted `assertStringContainsString`, which
  `PrTitleScopeGateTest` uses for both its refusal and its happy path — so the red-then-green
  run meant to prove the detector worked came back green and exposed it.

Every one of those errors pointed the same way: **fewer gates, more coverage, no
complaint.** That is the direction this whole ADR is about, and it is why the enumeration
carries a negative test of its own rather than being trusted because it is the thing doing
the trusting.

## Consequences

### What this costs

A negative test per gate, and they are not free — several need the gate to accept a path
or root argument so it can be pointed at a fixture. That option is additive, and six
scripts already carry one for exactly this purpose. Where a gate is mid-rewrite or its
negative test is unwritten, the enumeration carries a `PENDING` entry stating what writing
it would take. `PENDING` is capped by `EXEMPT_CEILING`, so the list can shrink freely and
can only grow through a line in a diff.

### What cannot be tested, and is recorded rather than omitted

Some gates have no refusal a local process can observe: `actionlint` and `zizmor` judge
the workflows and need a GitHub runner; the SLSA generator needs OIDC id-token minting and
a real release event; `composer test` is the process the assertion runs inside. These are
`UNFALSIFIABLE` entries stating what guards the gate instead — for `composer test`, the
test-yield gate, which does have a negative test.

The set of third-party actions is pinned, so a blind spot may exist and may not widen
unnoticed. The set of steps carrying `continue-on-error: true` is pinned for the same
reason: a step that gains it stops being able to fail while keeping the name that says it
can, which is this finding arriving as a one-line YAML edit.

### What is deliberately not claimed

That every declaration still plants the _right_ defect. Nothing short of mutating each
gate and re-running proves that, and that is what was done by hand, once, per gate, with
the red and the green recorded. What is enforced continuously is the cheap decay: a
negative test whose failing case is deleted and whose passing case survives no longer
contains an observation of refusal, and stops satisfying its declaration.

### The enumeration is itself a gate

So it has a negative test, for the same reason everything else does. A derivation that
quietly stops finding gates reports a fully covered repository, and nobody would notice,
because it would be green.

## Alternatives considered

**Mutation testing instead.** Infection already runs, and it measures whether tests catch
mutants in `src/` — not whether _gates_ catch defects. No mutation operator reverts
`--untracked` on a `git grep`, adds `continue-on-error` to a job, or removes a `shell:
bash` line so `tee` swallows an exit code. Six of the nine are invisible to it entirely.

**A checklist in review.** Every one of the nine passed review, four of them repeatedly.

**Deleting the gates that cannot be tested.** Rejected for `actionlint`, `zizmor` and
SLSA provenance, which do real work; a gate that cannot be exercised locally is still
worth having, provided the gap is stated rather than implied by absence.

## References

- [ADR-0001](0001-ci-gates-and-adr-discipline.md) — CI gates and ADR discipline
- [ADR-0041](0041-the-token-vault-takes-a-connection.md) — a control is not implemented
  because a class exists
- [ADR-0045](0045-a-control-status-is-observed-not-written.md) — a status is observed,
  not written
- `tests/Unit/Tooling/PipelineGateCoverageTest.php` — the pipeline enumeration
- `tests/Unit/Integrity/GateNegativeCoverageTest.php` — the ratchet enumeration
- `tests/Unit/Tooling/TestYieldGateTest.php` — the exemplar that predates this ADR
- `tests/Support/Gates/GuardsGate.php` — the declaration attribute
