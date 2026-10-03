# ADR-0068: A baseline records what was measured, and a seal is concrete on purpose

## Status

Accepted. Adds two `#[Internal]` interfaces — `Pulsar\Auth\Internal\Authorization\BufferedDecisionSinkInterface`
and `Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistryInterface` — and moves
three writes onto `DecisionRecordingState`, whose two fields become `private(set)`.
Purely additive on the `#[Api]` surface: no public signature changes, no removals.

Continues [ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md),
whose argument about the evidence gatherer's observers turns out to be the argument
seven other findings need, and applies the rule
[ADR-0041](0041-the-token-vault-takes-a-connection.md) states about controls to
the suppression baselines themselves.

## Context

`composer class-shape` reads `tools/php/substitutability-baseline.json`, a list of
findings the gate agrees not to report. `tests/Unit/Tooling/AnalysisBaselineRatchetTest.php`
compares that list's length against a ceiling recorded in
`tools/php/analysis-baseline-ceiling.json` and fails when it grows, so that burying a
finding costs a visible line in a diff.

Both halves were describing a position nobody held.

### The ceiling was not a measurement

The baseline committed at the branch tip of 25 August 2026 holds 1254 entries and carries
`"generated": "2026-08-21T05:00:51+00:00"` — generated against a tree that is not HEAD,
and unchanged through the six commits that landed after it. The ceiling was set to 1254
to match.

Measured on 2026-08-26, by running the gate in a clean worktree checked out at
that tip with no uncommitted changes:

| Measured at HEAD                                            | Count    |
| ----------------------------------------------------------- | -------- |
| findings the gate reports                                   | **1292** |
| baseline entries                                            | 1254     |
| baseline entries whose address no longer produces a finding | 52       |
| findings the baseline records                               | 1202     |
| **findings recorded nowhere**                               | **90**   |

`composer class-shape` was therefore already failing at HEAD, by 90 findings, and had
been since before this branch existed. The number in the ceiling file was not a high-water
mark that had been held; it was the length of a stale artefact.

### Which findings this branch actually introduced: one

The compliance spine on this branch measures 1293 findings against HEAD's 1292. The
difference is exactly one dependency site — `ControlEvidenceGatherer` on
`AiTransparencyObserver` — plus six addresses that moved when the file grew. The 85
findings the gate reported as unbaselined were checked one by one against the HEAD
measurement: **all 85 are present at HEAD, at the same addresses**, 38 as findings the
baseline never recorded and 47 as findings whose recorded address had gone stale.

Two independent routes agree. Of the 70 files those 85 findings involve — the depending
file and the declaring file of each target — 69 are byte-identical to HEAD, and the
seventieth (`RuntimeVerifier.php`) differs only by a doc comment and one string literal
inside a method body, in a class whose declaration is unchanged.

### The one finding the spine added is a seal

`ControlEvidenceGatherer`'s constructor already carries the argument, written when the
observer was added: `MeasuringComponent` admits an `Observation` only from a class
compiled out of `src/Compliance/Evidence/`, so a substitute supplied from outside that
directory could not produce a fact at all, and a substitute inside it is a new producer
rather than a decoration. An injectable seam there would be a hole in the seal ADR-0050
built. Three of that constructor's other parameters are already recorded for the same
reason.

That argument is not unique to the gatherer. Seven of the 38 unrecorded findings are the
same shape, and in six of them the source already says so in as many words —
`ExtensionBootstrap::surfaces()`: _"must not be settable: a host that could hand the
bootstrap a surface map could publish an extension's private types on its behalf"_.

## Decision drivers

1. A ratchet that compares against a number nobody measured ratchets nothing.
2. A finding recorded because it is genuinely pre-existing is not the same act as a
   finding recorded to make a gate green, and a diff must be able to tell them apart.
3. Where concreteness is the security property, an interface is a regression dressed as
   a fix — and fixing two of thirty-five sites on one type is arithmetic, not repair.

## Decision

### 1. The ceiling is set from a measurement, and the measurement is stated

`tools/php/analysis-baseline-ceiling.json` records the figure the gate reports on the
tree the commit ships, together with the HEAD measurement it is compared against. The
figure recorded here is **below** the 1292 measured at HEAD: the spine added one finding
and seven were fixed in this commit.

Regenerating the baseline is what makes its 52 stale addresses stop pointing at nothing
and its 90 unrecorded findings stop being invisible. Neither is a new suppression: the
same findings are reported by the gate before and after, and the file finally says so.

### 2. Seven findings are fixed rather than recorded

| Finding                                                                    | Fix                                                                   |
| -------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| `DecisionRecordingState::$nested` (undecidable)                            | queue behaviour moved onto the owner; `private(set)`                  |
| `DecisionRecordingState::$refused` (externally written)                    | `refuse()` on the owner; `private(set)`                               |
| `AuthorizationDecisionFlushListener` → `BufferedAuthorizationDecisionSink` | new `BufferedDecisionSinkInterface`                                   |
| `AiLifecycleManager` → `MonitoringHookRegistry`                            | new `MonitoringHookRegistryInterface`                                 |
| `HighRiskObligationsGate` → `MonitoringHookRegistry`                       | the same contract                                                     |
| `ComplianceVerificationWiring::breachNotificationCheck()`                  | returns `ComplianceCheckInterface`, which is all its one caller needs |
| `ScopedContainerProxy::scopedRouter()`                                     | returns `RouterInterface`, which is all both its callers need         |

`DecisionRecordingState` is the substantive one. The Gate appended to `$nested`,
incremented `$refused`, and `array_shift()`ed the queue it had filled — three writes to
another object's state, in the class whose whole job is to be correct about ordering
under Fiber interleaving. `array_shift()` on a foreign property is also the write no
static analysis can attribute to an owner, which is why the field had a verdict of
"undecidable" rather than a verdict.

### 3. Thirty-one are recorded, each with a reason

**Seals (7).** Concreteness is the security property, exactly as in the gatherer:
`BindingProvenance` (×2) attests only objects the framework's own binder produced;
`ExtensionSurfaces` (×3) decides which of an extension's types a peer may reach;
`ScopeRegistrations` and `ScopedContainerProxy` are the sandbox's own ledger and the
sandbox itself. A substitutable seal is a seal that answers whatever it is told to.

**The authentication publication channel (2).** `AuthenticationState::context()` returning
`SecurityContext`, and `AuthenticationMiddleware` taking the state. The class exists to
replace a request attribute any frame could write; an interface at either point restores
the property it was built to remove.

**The composition root (3).** `ComplianceVerificationWiring` building an `EvidenceChain`
and a `CustomControlRegistry`, and `MicroKernel` holding a `RouteAccessRegistrar`.
Naming concrete types is what a composition root is for, and three of `MicroKernel`'s four
self-built collaborators are already recorded.

**Returned value objects (5).** `CheckResult` (×2), `JobResult` (×2) and
`AiActRequirements`. Giving a result record an interface is not a seam; it is a second
name for a shape. `AiActRequirements` is one of nineteen sibling `*Requirements`
returns, eighteen of which are already recorded.

**Would need a new public interface (14).** `MetricRegistry` (×2 of 35 sites),
`Counter` (×2 of 6), `AccountTakeoverGuard`, `SessionGuard`, `SessionManager`,
`AuditLogger`, `RequestContextHolder` (×1 of 9), `DataPurgeOrchestrator` (×2),
`ComplianceVerificationEngine`, `MiddlewareRegistry`, `RumUrlLabels`. Each fix is a new
`#[Api]` interface adopted at every site of that type, not at the one this branch happens
to report — a `MetricRegistryInterface` used at two of thirty-five sites leaves
thirty-three welded and buys the count two. Those are real seams and they are worth
opening; each belongs in a change scoped to that type, with its own ADR and its own
snapshot diff, not smuggled into a commit whose subject is a gate.

## Alternatives considered

### Record all 38 and raise the ceiling by 39

Rejected as written, accepted in part. Recording a finding is defensible when it is
measured to pre-date the change and the reason is stated per item; it is not defensible
as a bulk action, which is what a `--generate-baseline` diff looks like when nothing
argues for the entries in it. Seven were fixed, and the thirty-one that remain are
grouped above by the reason each one stays.

### Fix all 38

Rejected. Eleven of the twenty-seven distinct target types would need a new `#[Api]` interface
during the RC phase, and seven of the findings are seals where the interface is the
regression. A change that expands the framework's `#[Api]` surface by eleven interfaces is
not a change whose subject is "make a gate green".

### Teach the gate to exempt seals, composition roots and result records

Rejected. Every one of those exemptions would be defensible on its own, and each would
reduce the gate's own output by tens of findings. Changing an analyser's rules inside the
commit that makes the analyser green is the one move a reviewer cannot check cheaply, and
`DataTypeRule`'s treatment of a `?Throwable` property — which is why `JobResult` is
classified as a collaborator at all — deserves to be argued on its own merits rather than
as a way of reaching a number.

## Consequences

### Positive

- The ceiling is a figure someone measured, in a worktree named in this ADR, and the
  measurement can be repeated.
- `composer class-shape` reports the position it is actually in, rather than failing at 90
  findings it cannot express.
- Three writes to another object's state are gone from `Gate::record()`, and the
  authorization decision queue keeps its own invariants.
- Two collaborations that were welded to a `final` class are contracts.

### Negative

- The recorded figure is 32 larger than the one it replaces, and a reader who compares only
  those two numbers will read it as thirty-two findings buried. The tables above are the
  answer, and the HEAD measurement of 1292 is what makes them checkable.
- Fourteen findings are recorded with "the fix is a new public interface" and no date
  attached to that work.

### Neutral

- The baseline's own `generated` timestamp becomes meaningful again; a future
  `--generate-baseline` run against a tree that is not the one being shipped will produce
  the same drift, and the ratchet will catch it as growth rather than as staleness.

## Security impact

`DecisionRecordingState` becomes unwritable from outside its own class, which removes
three unattributed writes from the authorization decision path. The two new interfaces are
`#[Internal]` and change no trust boundary: the buffered sink and the hook registry were
already chosen by a composition root. No seal is opened — the seven findings where opening
one was the available "fix" are the seven that stay recorded.

## Performance impact

None. Three method calls replace three property writes on the same object, on a path that
already builds an `AuthorizationDecision` per decision.

## Migration / rollback plan

Nothing to adopt: no public signature changed. To revert, restore the previous baseline and
ceiling — which restores a gate that fails at 90 findings, so a revert should be paired
with the measurement that justifies whatever ceiling replaces this one.

## Links

- [ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md) — the seal the
  gatherer's observers protect, and the argument seven of the recorded findings reuse
- [ADR-0041](0041-the-token-vault-takes-a-connection.md) — a control that reports
  itself implemented because a file exists; a ceiling that reports a position because a
  file is that long is the same defect
- `tools/php/analysis-baseline-ceiling.json` — the ceiling, and the measurement behind it
- `tests/Unit/Tooling/AnalysisBaselineRatchetTest.php` — the ratchet that reads it
