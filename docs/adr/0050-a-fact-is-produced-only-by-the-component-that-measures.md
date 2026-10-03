# ADR-0050: A fact is produced only by the component that measures, and only a measurement proves behaviour

## Status

Accepted. Narrows types carrying `#[Api(since: '1.0.0-rc.12')]` during the RC phase,
which [ADR-0001](0001-ci-gates-and-adr-discipline.md) asks us to justify rather than
avoid. Continues [ADR-0045](0045-a-control-status-is-observed-not-written.md), which
made a control status impossible to write as a literal, and closes the two holes three
adversarial reviews found in it.

## Context

ADR-0045 removed `ControlStatus` and built a vocabulary in `src/Compliance/Control/`
where an outcome is computed from graded observations rather than written down. Three
reviews then defeated it in turn, each time using nothing but public `#[Api]` factories,
with no container, no deployment and no measurement.

### Hole one: private constructors, public factories

Every value in the vocabulary had a private constructor. Every value also had public
named constructors, because the gatherer in `src/Compliance/Evidence/` is a different
class in a different namespace and PHP gives it no other way in. Composing them is one
expression:

```php
$evidence = new ControlEvidence(...$fiftyOneObservations);   // public, no provenance
Observation::measured($id, Measurement::completed('x', [ExecutedSubject::passed('x', 'Implemented.')], 'x'), Attacker::class);
ControlFinding::assess($declaration, $evidence);             // public, takes the set from its caller
```

`ControlEvidence::__construct` validated totality and recorded no provenance whatsoever,
so a composed set was indistinguishable from a gathered one and everything downstream
inherited its authority. `ExecutedSubject::passed()` and `failed()` were a caller-supplied
boolean spelled as two method names, both taking only strings. `Inspection::membership()`
computed `in_array($member, $roster)` over a roster the caller wrote.

Three attempts had been made to fix this by narrowing signatures. Each moved the lie one
call deeper and none removed it: the code that measures must be able to SAY what it saw,
and the same words are available to code that saw nothing.

`OutcomeSealTest` — the test written to make this impossible — passed against every one
of those attacks. It walked the public methods of the vocabulary and asserted that no
PARAMETER was named like an outcome or typed like a grade. That is a rule about spelling,
and it is ADR-0041's defect one meta-level up: the subsystem was reported sealed because
the source looked the way a sealed subsystem would look.

### Hole two: Resolved proved behaviour

`ObservationGrade::provesBehaviour()` returned true for `Measured` **and** `Resolved`.
What `Resolved` records is `ContractResolution::discharged()`, which is literally
`in_array($concrete, $accepts)` — which class is bound to a contract, and is it on the
allow-list. That is "the class exists" with one extra lookup, in the vocabulary built to
forbid exactly that claim. On a fully-equipped deployment, 46 controls were Satisfied on
it and nothing else.

## Decision

### 1. No compliance value is constructible from outside the component that measures

Every class under `src/Compliance/Control/` has a private constructor, and every named
constructor that produces a fact refuses a caller that is not the component that
measures. `MeasuringComponent::assertProducing()` decides that, and it decides on the
**file the calling class is compiled from**, not on its name and not on its namespace: a
namespace is something any file anywhere can declare, and a directory in this package is
not.

**PHP cannot express this in the type system and we stopped pretending otherwise.** There
is no package-private visibility: a member is private to a class or protected to a
hierarchy, full stop. So the seal is enforced where the language does have an answer —
the identity of the code that is calling — and the docblocks say so in those words.

What it stops: every composition of public factories, from a probe, a mapping, an
extension, an application or a test.

What it does not stop, deliberately: Reflection. `newInstanceWithoutConstructor()` plus
an explicit constructor invocation reaches any of these values, and that is the escape
the tests use, concentrated in one file
(`tests/Support/Compliance/ReflectedVocabulary.php`) that says what it is. Reflection
announces itself; a factory call does not. If a fixture could be built without
Reflection, so could a fabricated compliance report.

### 2. Serialization, cloning and `var_export` do not reopen it

A private constructor settles who may call a value into existence and settles nothing
about the three ways PHP hands you one without a constructor. `unserialize()` sets every
property from a string; `__set_state()` rebuilds from executable `var_export` output;
`clone` copies a value out of the run that produced it. All three refuse, via the
`SealedValue` trait, and the seal test attempts all three against every value.

### 3. Invariants moved from the factories onto the values

"Measured, and nothing was measured", "every member of no members complies" and "no
subject, over an estate that has two" are refused in the private constructors rather than
only in the sealed factories, so they are properties of the value and hold for the
Reflection escape too.

### 4. Only `Measured` proves behaviour

`ObservationGrade::provesBehaviour()` returns true for `Measured` alone.

`Resolved` is still produced, still printed, and still worth reading: a report saying
`TokenStoreInterface -> InMemoryTokenStore; tokens are held in process memory` tells an
assessor which class to go and look at. It is context, printed with its grade beside it,
and it is never the reason a control holds.

### 5. The summaries say what the evidence says

`ProbeVerdict::observedSummary()` hard-coded `'Observed working, on %d required fact(s)'`
and emitted it whenever the proof list was non-empty — which, while Resolved proved
behaviour, included verdicts where nothing had been executed and the sentence was false.
Both summaries are generated from the deciding observations now and print each fact's
grade, so the sentence at the top of a finding is checkable against the lines under it.
`configuredButUnobserved()` became `claimedButUnobserved()`: most of what reaches it is
resolved identity, and calling that "configured" would understate it.

## Consequences

**Controls that were Satisfied on resolved identity alone are Unsatisfied.** On a
deployment carrying every implementation this release assesses, probed coverage falls
from 78/96 to 32/96. Per framework, satisfied controls before → after:

| framework | probed | before | after |     | framework | probed | before | after |
| --------- | ------ | ------ | ----- | --- | --------- | ------ | ------ | ----- |
| ccpa      | 5      | 3      | 1     |     | mdr       | 3      | 3      | 2     |
| dora      | 5      | 4      | 1     |     | nis2      | 5      | 5      | 1     |
| eidas     | 2      | 2      | 0     |     | nist_csf  | 11     | 10     | 3     |
| gdpr      | 4      | 4      | 2     |     | pci_dss   | 3      | 3      | 2     |
| hipaa     | 9      | 7      | 4     |     | psd2      | 2      | 2      | 1     |
| hl7_fhir  | 1      | 1      | 1     |     | soc2      | 18     | 15     | 5     |
| iso13485  | 2      | 2      | 2     |     | swift_csp | 8      | 7      | 3     |
| iso27001  | 6      | 6      | 3     |     | iso42001  | 14     | 4      | 1     |

ISO 42001 also loses seven of its ten Partial verdicts: `Partial` has to prove the part
it claims, and "the AI governance extension is installed" proved nothing the extension
does.

eIDAS satisfies nothing at all on an equipped deployment, and that is the correct report.
Pulsar observes no signature being created or validated, so it has nothing to say about a
deployment's trust services beyond which classes are wired.

**No measurement was invented to keep a control green.** Every one of the 46 that moved
reports "Claimed and not observed", names the class that was found and its grade, and
carries its remediation. The alternative — writing an observer that "exercises" a
subsystem by constructing it — is the defect this ADR exists to prevent, wearing this
ADR's approval.

**The figures are recorded per framework in `FrameworkMappingTest`, not thresholded.**
Moving one requires either a probe gaining a real measurement or losing one, and saying
which in the probe.

**Gathering pays one small backtrace per fact**, about fifty per report, against a run
that already opens a database session, executes every registered health check and
recomputes an HMAC per stored evidence record.

**`ControlFinding::assess()` and `ProbeVerdict::reach()` stay public.** Review's objection
to them was exact — they took the evidence set from a caller with no proof of where it
came from — and it is answered by sealing `ControlEvidence` rather than by sealing them.
Given facts somebody gathered, both are pure functions of those facts; given facts nobody
gathered, there is no call to make, because there is no such value.
