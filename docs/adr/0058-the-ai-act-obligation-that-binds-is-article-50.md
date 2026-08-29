# ADR-0058: The AI Act obligation Pulsar can carry is Article 50, and it binds now

## Status

Accepted. Adds one `ComplianceFramework` case (`AiAct`), one requirements object,
one control mapping with 22 declarations, one `ObservationId`, one probe, and an
`Article 50` transparency layer in the `ai-governance` extension. Four new
`#[Api]` types on the extension surface, one new `#[Api]` probe in core. Additive
only.

## Context

Pulsar maps eighteen compliance frameworks. Until this decision the EU AI Act was
not among them, while ISO/IEC 42001 — a voluntary management-system standard for
the same subject matter — was. A framework aimed at regulated domains mapped the
optional standard and not the binding law.

The gap was not noticed earlier because the Act was widely treated as a 2026-and-
later problem. That is no longer accurate, and the dates are what decide
everything below.

### What actually binds, as of this decision

Regulation (EU) 2024/1689 applies in stages, and the digital omnibus in force
since 27 July 2026 moved one of those stages again:

| Obligation                                       | Applies from      |
| ------------------------------------------------ | ----------------- |
| Article 4, AI literacy                           | 2 February 2025   |
| Article 5, prohibited practices                  | 2 February 2025   |
| Chapter V, general-purpose model providers       | 2 August 2025     |
| **Article 50, transparency**                     | **2 August 2026** |
| Chapter III high-risk, Annex III use cases       | 2 December 2027   |
| Chapter III high-risk, Annex I safety components | 2 August 2028     |

The omnibus deferred the high-risk regime. It did **not** touch Article 50. The
consequence is counter-intuitive and is the reason this ADR exists: the chapter
everyone builds for is the one that no longer binds, and the chapter nobody had
built for is the one in force.

### Why Article 50 is the article a framework can carry

Almost every AI Act obligation is discharged in conduct or in documents. "Do not
engage in this practice", "hold this technical file", "register in that database",
"report this incident within fifteen days" — a web framework observes none of
these, and a compliance report that graded them from container bindings would be
repeating the failure this codebase spent a release removing, where ISO 27001
A.5.1 was once graded from the existence of a CSP header.

Article 50 is different in kind. Its duties are discharged in what a response
contains:

- 50(1) — a person interacting with an AI system must be told so, in a clear and
  distinguishable manner, at the latest at first interaction (50(5)).
- 50(2) — synthetic audio, image, video or text must be marked in a
  machine-readable format and detectable as artificially generated, with
  solutions effective, interoperable, robust and reliable _as far as this is
  technically feasible_.

Both live where a framework already is.

## Decision

### 1. `AiAct` becomes a framework, and its requirements object asserts nothing

`AiActRequirements` implements none of the `Has*` requirement interfaces. This is
the finding, not an omission. Those interfaces express duties over a deployment's
security _configuration_ — audit retention, encryption, breach deadlines. Every
AI Act article that would justify one (12 logging, 15 cybersecurity, 18 ten-year
retention, 19 six-month log retention) sits in Chapter III.

Chapter III does not apply yet. `ComplianceProfileResolver` takes the most
restrictive value across enabled frameworks, so asserting an Article 18 retention
floor today would make enabling `AiAct` silently ratchet an unrelated control on
the authority of an article that binds nobody. When Chapter III applies, this
object gains the interfaces its articles then justify. Not before.

### 2. Twenty of twenty-two controls name an operator artefact

`AiActMapping` declares 22 controls. Two carry a probe. The other twenty name the
artefact an assessor must be shown.

That ratio is the honest one. It follows the precedent `DsaMapping` set (nine
operator responsibilities, one probe) and it exists so the report doubles as the
assessor's checklist rather than padding a percentage.

The deferred Chapter III controls are declared anyway, each stating **BINDS FROM**
its own date. A deployment placing a high-risk system on the market is building
for December 2027 now and needs the list; none of them is graded as a present
failure, because a duty that has not commenced cannot be breached.

### 3. Article 50 splits capability from discharge, strictly

Three controls, and the split between them is the load-bearing part:

- `ai-act-art-50-capability` is **probed**. It observes that the extension is
  active and a transparency contract resolved — that the deployment has somewhere
  to declare a position and something to mint marks from.
- `ai-act-art-50-1` and `ai-act-art-50-2` remain **operator artefacts**. Whether a
  person actually saw the notice, and whether real output actually carried the
  mark, happen where Pulsar cannot look.

The probe's own docblock says what it does not establish, so the distinction
survives being read out of context.

### 4. An Article 50 position is declared, and an incoherent one is refused

`AiTransparencyPolicy` makes one state unrepresentable: _this surface talks to
people, claims no exemption, and carries no notice_. Article 50(1) does not permit
it, so the type does not hold it.

The Act grants four exemptions and they are not interchangeable, so
`TransparencyExemption` records which paragraph each one discharges:

| Exemption                  | 50(1) disclosure | 50(2) marking |
| -------------------------- | ---------------- | ------------- |
| None                       | —                | —             |
| Obvious from context       | discharged       | —             |
| Assistive editing only     | —                | discharged    |
| Law-enforcement authorised | discharged       | discharged    |

Obviousness excuses telling someone they are talking to a machine; it does not
leave a generated video unmarked, because a viewer's suspicion is not a
machine-readable mark. The law-enforcement exemption is refused outright on a
surface available to the public to report a criminal offence, which Article 50(1)
carves back out of it in terms.

Every exemption that excuses something names the artefact justifying it. A claim
with no stated basis is the same as no claim, and an exemption a deployment could
assert silently would turn the obligation into an opt-out — a duty nothing can be
observed to fail is not a duty.

### 5. Marking is at the delivery boundary, and the type says so first

`SyntheticContentMark` states in its opening paragraph that it is not a watermark:
nothing is embedded in pixels or audio samples, and nothing survives a re-encode
or a screenshot.

Article 50(2)'s "as far as this is technically feasible" is the qualifier that
makes this honest rather than partial. The boundary is as far as a server-side
framework reaches; the machine-readable and detectable limbs are delivered in
full, as an RFC 8941 structured-field assertion leading with `ai-generated=?1`.
The robustness limb for media is discharged by a provenance standard such as C2PA
applied where the media is produced. `ai-act-art-50-2` says this where an assessor
will read it, rather than leaving it to be discovered.

## Consequences

The framework count moves 18 → 19, which changes `ComplianceFrameworkTest` and
requires an arm in **two** exhaustive `match` expressions, not one:
`ComplianceProfileResolver::collectRequirements()` and
`AuditReportGenerator`'s display-name map. The second was missed on the first
pass and found by `AuditReportGeneratorTest::testAllFrameworkDisplayNames`
throwing `UnhandledMatchError` — which is the argument for leaving both
expressions exhaustive rather than giving either a `default` arm.

`ai-governance` gains a service. It is bound unconditionally and behind no
configuration switch, because Article 50 has applied since 2 August 2026 and a
deployment cannot turn the duty off. What a deployment chooses is what it
_declares_; whether the contract exists is not a choice, or an application would
discharge Article 50 by never wiring it.

The extension's manifest remains `"kind": "product"`, so it does not load unless
an operator lists it. A deployment that operates AI systems and does not enable
the extension has no way to express a duty it already owes, and
`AiTransparencyProbe` reports exactly that rather than staying silent.

### What was considered and rejected

An observation named `AiTransparencyDisclosuresComplete` was drafted and removed
before it shipped. It would have been **always true**: `AiTransparencyPolicy`'s
constructor already refuses a policy that owes a notice and lacks one, so the
observation restated a type invariant. A check that cannot fail is
indistinguishable from no check.

An observation for _surfaces actually declared_ was also dropped. The core
evidence gatherer reads bindings and core collaborators; `ComplianceScope`
produces only `ObservationGrade::Asserted`, which by construction cannot support
`Satisfied`. There is no mechanism today by which an extension contributes a
_measured_ observation, so claiming that measurement would have been a fiction.
Building that mechanism is a separate decision, not a side effect of this one.
