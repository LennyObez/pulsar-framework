# ADR-0065: An incident register is measured by recording something, and a pseudonym by erasing it

## Status

Accepted. Adds two `#[Api(since: '1.0.0-rc.12')]` observers to the Compliance module
(`PseudonymizationObserver`, `IncidentRegisterObserver`) and three cases to the
`ObservationId` vocabulary (`IdentifierPseudonymizedAndErased`,
`IncidentRecordedAndRetained`, `PseudonymTablePersistence`). Two existing `#[Api]`
probes — `PseudonymizationProbe` and `BreachNotificationProbe` — stop extending
`CapabilityProbe` and implement `ControlProbeInterface` directly; their public
signatures are unchanged. Everything else is additive, which is what
[ADR-0001](0001-ci-gates-and-adr-discipline.md) asks for during the RC phase.

Continues [ADR-0041](0041-the-token-vault-takes-a-connection.md),
[ADR-0046](0046-a-claim-is-something-the-installation-delivers.md),
[ADR-0061](0061-a-loaded-extension-is-not-a-measurement.md) and
[ADR-0062](0062-proof-must-be-about-the-control-subject.md).

## Context

ADR-0046 made enabling a framework in `config/compliance.php` a claim the build
holds you to: `composer compliance:check` fails on any control an enabled framework
declares and the deployment does not show. GDPR is enabled, and two of its controls
failed on every default installation:

- **Art 25, data protection by design and by default**, rested on
  `PseudonymizationServiceInterface` resolving to `PseudonymizationService`.
- **Art 33, notification of a personal data breach**, rested on
  `IncidentReporterInterface` resolving to `FileIncidentReporter`.

Both are ADR-0041's defect: which class would serve a request, never that a direct
identifier was replaced or that an incident was ever written down. The failure was
left standing rather than configured away, and `config/compliance.php` said in so
many words what would close it and what would not — an observer that puts a value
through the subsystem, as `TokenVaultObserver` does for the token vault; not
something that "exercises" a service by constructing it.

There was a second reason these two could not simply be demoted and forgotten.
ADR-0061 and ADR-0062 together took the equipped fixture down to 11 satisfied of 100
assessed, and ADR-0063 and ADR-0064 had carried it back to 13 by the time this
decision was taken — so a report in which almost everything reads "claimed and not
observed" carries little more information than one in which a third read a false
green. Operators stop reading
an instrument like that. Where a thing can honestly be measured, measuring it is
worth more than demoting it.

## Decision

**1. `PseudonymizationObserver` — four subjects against the live service.**
A synthetic identifier (`compliance.pseudonym_probe:` plus 32 random hex characters
from the framework's CSPRNG) is pseudonymised; the pseudonym must carry none of it;
the mapping must be on record; `resolve()` must return the identifier byte for byte,
because Art 4(5) pseudonymisation is reversible by its holder and an irreversible
hash is a different measure with different obligations; and then the mapping is
erased through `ForgetServiceInterface`.

**The erasure is the control and the cleanup at once**, and that is what lets this
measurement write to a production re-identification table. Article 17 erasure is what
`ForgetService` exists to perform, so the step that removes what the check wrote is
the same step that evidences the requirement. It runs in a `finally`, so a failure
earlier still erases; if the erasure itself fails, the report says so as a failed
subject and names what was left behind.

**2. `IncidentRegisterObserver` — three subjects, and one row it cannot take back.**
A Low-severity incident is recorded through the live register, must come back with an
id and a usable timestamp, must be retrievable by that id — on the file register that
read goes to disk — and must come back with its severity, title, metadata and
timestamp unchanged, because a deadline computed from a record whose clock moved is
wrong in the direction nobody checks.

`IncidentReporterInterface` has no removal and must not grow one: a register whose
entries can be deleted evidences nothing, and adding a deletion seam to an
append-only security log so a compliance check could tidy up after itself would
damage the control in order to measure it. So one row is left per report run. The
cost is bounded — lowest severity, a title that says it is not a security event, one
filterable source id, and a report that is run by an operator or a pipeline rather
than by a request — and there is something on the other side of it: a register
carrying a dated, retrievable self-test on every compliance run shows an assessor
that it was working on those dates, which the report's own sentence about it cannot.

**The Low severity is load-bearing and is asserted, not assumed.**
`BreachNotificationCheck` reads the same register at High and above and fails a
deployment holding a reportable incident past its notification deadline. A probe row
written at any higher severity would, hours later, fail the very check it exists to
support — the measurement would have manufactured the breach it reports on. Two
classes have to agree about that, so `IncidentRegisterObserverTest` asserts the row is
invisible at the severity the deadline check inspects.

**3. Each measurement is paired with an ESSENTIAL identity fact, because neither can
see durability.** Both observers run in one process. `InMemoryPseudonymLookup` — the
development stub — and `InMemoryIncidentReporter` pass every subject either observer
runs and are empty again at the end of the request. A deployment on either can mint a
pseudonym it will never resolve, or record a breach it cannot produce tomorrow. So
`PseudonymTablePersistence` is added to the vocabulary as the pseudonymisation
analogue of `TokenVaultPersistence`, and `IncidentReporterResolved` is kept.

Without this pairing the change would have closed its own gate by re-opening
ADR-0041's defect one subsystem over.

**4. Both probes are declared directly against `ControlProbeInterface`.**
`CapabilityProbe` maps every required id through `RequiredFact::contributing()`, and a
contributing fact that is missing while another was measured yields **Partial** — which
`compliance:check` passes, because the gate fails on Unsatisfied. A table that forgets
its mappings and a register that empties on restart would then have produced a green
build. `PanAtRestProbe` was declared directly for the same reason and states it: these
failure modes are not interchangeable and must not print the same way.

**5. The estates are `personal_data` and `incident_response`, derived from the fact's
identity as ADR-0062 requires.** `identifier_pseudonymized_and_erased` is deliberately
NOT filed under confidential information, so it cannot carry ISO 27001 A.8.12, which
regulates that estate and asks a broader question — whether leakage prevention is
APPLIED to the systems handling sensitive information — that one identifier through
the framework's own service does not answer.

### What was deliberately not done

**`IncidentResponseProbe` did not take the register measurement**, though the estate
join would let it carry all seven of its controls. Recording, dating and retrieving a
record is the whole of what a framework delivers toward a NOTIFICATION obligation:
GDPR Art 33 and NIS2 Art 23 are statements about a deadline, and a deadline runs from
a timestamp on a record somebody can still find. The controls that probe serves are
about an incident-management PROCESS — DORA Articles 17-23 ask an entity to "detect,
manage and notify"; SOC 2 CC7.3 and CC7.4 for evaluation and response — and a register
that works evidences one third of that. Whether the third is enough is a reading of
seven standards one at a time, and it is deferred to whoever does that reading rather
than taken as a consequence of this one. Those controls stay Unsatisfied, which is
their real state.

**No observer was built for HTTP transport security**, and none should be: TLS is
normally terminated in a proxy this process cannot see, so a "measured" fact there
would be a resolution wearing a Measured badge — the defect ADR-0061 exists to remove.

## What moved

Measured against the equipped fixture, per framework, by running the assessment:

| Framework | Control    | Before      | After     | Why                                                                |
| --------- | ---------- | ----------- | --------- | ------------------------------------------------------------------ |
| gdpr      | Art 25     | unsatisfied | satisfied | `identifier_pseudonymized_and_erased`, measured on `personal_data` |
| gdpr      | Art 33     | unsatisfied | satisfied | `incident_recorded_and_retained`, measured on `incident_response`  |
| nis2      | NIS2-Art23 | unsatisfied | satisfied | the same register measurement; one deployment, one answer          |

`SATISFIED_WHEN_EQUIPPED` therefore moves `gdpr` 0 → 2 and `nis2` 0 → 1, and the
equipped total 13 → 16 of 100 assessed. Nothing else moved, and that is checked rather
than assumed: `FrameworkMappingTest` asserts the figure for every framework and
`GdprMappingTest` asserts that Art 5(1)(f) and Art 32 are still claimed and not
observed.

**`composer compliance:check` no longer fails on Art 25 or Art 33 on a default
installation.** It still fails, on Art 5(1)(f) and Art 32, which are declared over
personal data and rest on cryptography this release does not measure over that estate.
That is the gate working.

Both controls are reachable in BOTH directions, proven by execution from four
deployment shapes each — absent, bound-and-unusable, bound-and-not-durable, working —
because a control that can only reach one outcome is a broken instrument whichever
direction it is stuck in.

## What this does not fix

Carried forward from ADR-0061 and ADR-0062, with what this change did to each:

1. **The grade-blind "all required present" test in `reach()` survives.** An
   `Observation` at Declared or Resolved still fills a required slot. This ADR works
   with that rather than against it — the pairing in decision 3 makes the identity
   facts ESSENTIAL, which is the tool the current table gives — and the underlying
   decision is still deferred to its own review.
2. **`MeasuringComponent` still seals who may produce a fact, not what they may say.**
   Both new observers are inside `src/Compliance/Evidence/`, so both are trusted to
   report what they saw; the estates they name are derived and beyond their reach, the
   prose is not.
3. **"Claimed and not observed" is still spelled `ControlOutcome::Unsatisfied`.** There
   is still no third column.
4. **Estate granularity is a judgement.** Two were made here and both were made narrow:
   the pseudonymisation fact is `personal_data` and not "data at rest", the register
   fact is `incident_response` and not "personal data". Filing either one level wider
   would have carried controls neither measurement is about — and the ISO 27001 A.8.12
   case in decision 5 is exactly that refusal, computed rather than argued.
5. **Flat subject equality still cannot express containment beyond the one enumerated
   pair.** Neither new fact needed it.
6. **A control that can never be Satisfied is as broken as one that can never be
   Unsatisfied**, and this change was made in that direction on purpose. It is also
   the direction that can inflate a report, so both controls were given a failing shape
   and a passing shape and both were executed.

And one residue this ADR adds, stated plainly because nothing else in the evidence set
has it: **`IncidentRegisterObserver` leaves a record behind on every run.** It is one
Low-severity row, filterable by source, self-describing in its title, and it is the
price of measuring an append-only register at all. If that price is ever judged too
high, the answer is to stop claiming Art 33 by removing GDPR from
`enabled_frameworks` — in a diff someone can read — and not to soften the measurement.
