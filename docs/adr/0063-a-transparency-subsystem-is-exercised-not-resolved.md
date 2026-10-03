# ADR-0063: A transparency subsystem is exercised, not resolved

## Status

Accepted. Purely additive: one `#[Api(since: '1.0.0-rc.12')]` interface
(`AiTransparencyDrillInterface`), one `#[Api]` observer (`AiTransparencyObserver`), one
`ObservationId` case (`ai_transparency_exercised`), and one binding in the
`pulsar/ai-governance` service provider. No signature changes, no removals.

Continues [ADR-0045](0045-a-control-status-is-observed-not-written.md),
[ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md),
[ADR-0058](0058-the-ai-act-obligation-that-binds-is-article-50.md),
[ADR-0061](0061-a-loaded-extension-is-not-a-measurement.md) and
[ADR-0062](0062-proof-must-be-about-the-control-subject.md). Constrained by
[ADR-0004](0004-extension-first-architecture.md) as superseded by
[ADR-0070](0070-the-extension-api-is-shared-the-trust-that-ships-with-it-is-not.md):
the constraint that binds this record is the manifest-driven lifecycle ADR-0070 kept,
not the third-party-parity claim it retracted.

## Context

The EU AI Act mapping satisfied **zero** controls on any deployment this release can
build. It was honest and it was inert. Both of its probes read a resolved identity, and
resolution stopped proving behaviour when ADR-0045 narrowed `provesBehaviour()` to
`Measured`.

ADR-0062's own residue names this state and calls it broken:

> A control that can never be Satisfied is the same class of broken instrument as one that
> can never be Unsatisfied. Do not trade one for the other silently.

Article 50 is the worst place in the catalogue to leave such an instrument. It is the one
AI Act obligation a framework carries a real share of, because it is discharged in what a
response contains rather than in a document — and it **has applied since 2 August 2026**.
The digital omnibus deferred the high-risk chapter to 2 December 2027 and 2 August 2028
and left this Article exactly where it was (ADR-0058).

**And it was stuck for a second reason nobody had found.** `ai_transparency_resolved`
could not be present on ANY deployment: `ComplianceCatalogWiring::OBSERVED_CONTRACTS`
resolved eight of the AI governance contracts and not `AiTransparencyInterface`, so the
fact read "nothing answered `AiTransparencyInterface`" against containers that had bound
it. One of the two required facts was structurally unreachable, for a reason that was an
omission in the composition root rather than a property of any deployment. Verified by
executing the assessment against `DeploymentUnderAssessment::fullyEquipped()` before any
edit.

## Decision

### 1. An observer that exercises the subsystem

`AiTransparencyObserver` produces `ObservationId::AiTransparencyExercised`, modelled on
`TokenVaultObserver`. Seven subjects run against the transparency subsystem that serves the
deployment:

1. a surface owing **both** Article 50 duties is declared, and the policy reads back
   intact — notice, language, `owesDisclosure()`, `owesMarking()`;
2. the declaration is visible where the report enumerates declared surfaces (answering a
   direct lookup and enumerating nothing are different failures);
3. a synthetic-content mark is minted for that surface;
4. the machine-readable form carries the kind, the model, the surface **and the generation
   instant it was handed** — a fixed 2026-08-02, so a marker that stamps its own clock
   fails here. The contract states that no generation time is taken from a clock; this is
   how that sentence becomes a measurement;
5. the mark renders to a transport field a proxy or crawler can detect on;
6. a mark for a surface nobody declared is **refused** — a mark that traces to no declared
   policy asserts nothing an assessor can corroborate;
7. what this check leaves behind is bounded (see §3).

Absent is not false. The extension is trust tier `verified`, kind `product`, so it does not
load unless an operator enables it. With no subsystem bound, the observer reports
`Measurement::couldNotRun()` — a gap. It is deliberately **not** `Observation::noSubject()`,
which would retire the control instead of failing it: a deployment with no way to declare an
Article 50 position is not out of scope for a duty it already owes. That evaporation is what
ADR-0062 spent itself removing from transmission security.

### 2. The dependency arrow is inverted, not crossed

The observer would rather hold `AiTransparencyInterface` and call it, the way
`TokenVaultObserver` holds `TokenizationServiceInterface`. It may not. That contract belongs
to an optional package, absent from the root autoload by ADR-0004, and `src/` today holds
**zero** compile-time references to any extension — `ControlEvidenceGatherer` carries every
extension contract as a plain string and says why.

So the framework declares `AiTransparencyDrillInterface` in `src/Compliance/Evidence/`, the
extension answers it from `Internal\Compliance\AiTransparencyDrill`, and the composition
root hands the implementation over. Same shape as `SpanProcessorInterface`, which the
framework declares and the OpenTelemetry extension answers.

The port returns **raw material only** — the policy as stored, the mark in both forms, the
declared surface ids. No method returns a verdict, a status, or a boolean meaning "that
worked", because every comparison that decides the fact is made inside the directory
`MeasuringComponent` seals (ADR-0050). An extension cannot produce an `Observation` at all,
so the worst an adapter can do is forge the material an assessor would ask to see, rather
than assert a conclusion the vocabulary would carry for it.

### 3. The residue is bounded structurally, because it cannot be withdrawn

This is the **second** measurement in the evidence set that writes, and unlike the token
vault it cannot undo what it wrote: `AiTransparencyInterface` has no withdrawal. Adding one
so a compliance check could tidy up after itself would grow a shipped contract for the
benefit of the thing that inspects it, and would break every implementer during RC.

The bound is structural instead. Declarations are keyed by surface, so everything the
observer writes goes under one reserved id — `compliance.transparency_probe`, which no
request routes to — and subject 7 establishes the bound by **declaring it a second time and
reading the store back**: the subsystem must then hold exactly what it held before plus that
one id. A store that appended would accumulate an entry per report, and that shows up on the
second declaration in the same run rather than after a year of them. The other half of the
same subject is that every surface the deployment had already declared is still declared
afterwards.

The residue is stated in the observation's detail line on the **passing** branch too. A note
that only appears when something goes wrong is a note nobody reads.

### 4. The count moves by exactly one

`ai-act-art-50-capability` now rests on the exercised fact. `ai-act-art-50-1` and
`ai-act-art-50-2` stay operator artefacts, naming the notice as rendered and a captured
response. Whether a person saw the notice and whether real output carried the mark happen
where no container can look, and grading either from a subsystem that _can_ produce them is
the inflation this whole subsystem exists to prevent.

## Alternatives rejected

**Import `AiTransparencyInterface` into `src/`.** Both gates would have allowed it: Deptrac
lists `AiGovernanceExtension` among `Compliance`'s permitted dependencies, and the contract
carries `#[Api]`, so `scripts/boundary_check.php` passes it. It is still wrong. The framework
does not require the package, `src/` names no extension anywhere, and a framework signature
referring to a class from an optional dependency inverts ADR-0004's arrow. A gate permitting
something is not the same as the architecture intending it.

**A measured observer for the notice or for HTTP transport.** Rejected outright, on the same
ground the HTTP half of transmission security was: notice rendering and TLS termination both
happen where this process cannot see, so a "measured" fact there would be a resolution
wearing a Measured badge — the exact defect ADR-0061 and ADR-0062 exist to remove.

**Adding `withdraw()` to `AiTransparencyInterface`.** See §3. A breaking interface change,
during RC, to make an inspector tidier.

**Making the observer's fake its own subsystem for the report.** A second, obliging
implementation kept for the compliance run is the artefact this subsystem exists to make
impossible. The drill reaches the same bound contract an application's own surfaces reach,
through the container.

## Consequences

Measured by executing the assessment against the real fixture, before and after:

|                                             | before                     | after                                       |
| ------------------------------------------- | -------------------------- | ------------------------------------------- |
| `ai_act` satisfied, equipped                | 0                          | 1                                           |
| `ai-act-art-50-capability`, equipped        | Unsatisfied on every shape | Satisfied, on a measurement                 |
| `ai_transparency_resolved`, equipped        | absent (unreachable)       | present                                     |
| Facts this change adds at grade `Measured`  | —                          | one, `ai_transparency_exercised`            |
| Writes this change adds to a compliance run | —                          | one declaration, under one reserved surface |

Nothing else moved. `AiTransparencyProbe` is declared by exactly one control in one mapping,
and `ai_transparency_exercised` and `ai_transparency_resolved` are required by no other probe.

### What this does not fix

- **The grade-blind required slot survives** (ADR-0062 residue 1). `AiTransparencyProbe`
  keeps `ai_governance_extension_active` and `ai_transparency_resolved` as required facts.
  Both are `Resolved`, so both can block a Satisfied verdict and neither can produce one.
  Here that asymmetry is the behaviour wanted, and it is named in the probe rather than
  relied on silently.
- **"Claimed and not observed" is still spelled `Unsatisfied`** (residue 3). A deployment
  with no AI governance extension gets a gap, which is right for a duty in force, but the
  vocabulary still has no word for "nobody looked". That is a decision for the outcome
  enum, not for one observer.
- **The subject stays caller-supplied inside `src/Compliance/Evidence/`** (residue 2). The
  estate travels with `ObservationId::subject()`, which is why the new fact cannot be
  mislabelled; the DETAIL prose is still whatever the observer says it is.
- **Twenty AI Act controls remain operator responsibilities**, and should. They are
  obligations of conduct and of documentation — do not engage in this practice, hold this
  file, register in that database — and no framework observes any of them.
