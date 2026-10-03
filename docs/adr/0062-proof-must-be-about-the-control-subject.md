# ADR-0062: Proof must be about the control's subject

## Status

Accepted. Adds a required parameter to `ControlDeclaration::probed()` and a required
parameter to `ProbeVerdict::reach()`, both carrying `#[Api(since: '1.0.0-rc.12')]`, which
[ADR-0001](0001-ci-gates-and-adr-discipline.md) asks us to justify rather than avoid.
Continues [ADR-0045](0045-a-control-status-is-observed-not-written.md) and
[ADR-0061](0061-a-loaded-extension-is-not-a-measurement.md).

## Context

ADR-0045 made a control's outcome a function of gathered facts. ADR-0061 made the GRADE
of a fact a function of what its check exercises. Both are about how a fact was obtained.
Neither asks what it was obtained ABOUT, and an adversarial audit found the whole
remaining class of defects sitting in that gap:

- **`extension_loaded('sodium')` carried nine controls across seven frameworks.**
  ADR-0061 answered half of that — a platform capability is not a measurement — and left
  the other half standing. Even at grade Measured, an answer about the PHP build is not
  an answer about the personal data GDPR Art 5(1)(f) protects.
- **HIPAA 164.312(e)(1), Transmission Security, could not be Unsatisfied.**
  `TransportSecurityProbe` required the database link alone, so a deployment using a unix
  socket, or SQLite, or no database, had no subject for the only fact behind the control
  and RETIRED it to NotApplicable — off the assessor's page, out of the coverage
  denominator — while serving ePHI over HTTP.
- **`scope.processes_personal_data = false` retired SOC 2 C1.2 and CC6.5.** Those are
  Confidentiality criteria. An entity that processes no personal data still holds
  contracts, pricing and source code, and still has to dispose of them.
- **`audit_chain_verified` carried twelve controls about the audit trail.** It recomputes
  HMACs over the compliance evidence register — the framework's signed log of its own
  verification runs — and not over the trail the application writes through
  `AuditSinkInterface`.

The four are one defect: the decision table had no notion of whether a measured fact was
about the SUBJECT of the control it decided.

## Decision

**1. `ControlSubject` — the estate a control regulates and a fact interrogated.**
A sealed `#[Api]` enum of twenty-one narrow estates. Narrowness is the security property:
an estate named one level too broadly re-opens the defect it was introduced to close, so
`SessionPayloads` is a case and "data at rest" is not, and `CryptographicPlatform` — what
the build offers — is kept apart from `KeyHierarchy` — what this deployment derives with.

### The objection, and why the general solution was taken anyway

Review argued the machinery was speculative generality: the subject mismatches anyone had
found were SOC 2 C1.1, C1.2 and CC6.5, and one unscoped copy of `DataErasureProbe` closes
those, so the burden lay on the general solution to name a case the narrow fix would miss.
Two answers, and the first is a correction found by running the assessment rather than by
reading it: **C1.1 is not one of the three.** It carries `AssetInventoryProbe`, which
declares no scope assertion at all, so nothing was retiring it. The real list is two.

The case the probe split misses is `TamperEvidentAuditProbe`: eleven controls across
eleven standards, all about the audit trail, all carried by a measurement of the compliance
evidence register. No split closes it, because the estate a probe would declare is the
estate of the facts it already requires — a probe naming its own subject agrees with itself
by construction. Only a control declaring, from the standard's own text, what it is about
can disagree with the probe a mapping assigned to it. That is the fourth case, it is worth
twelve findings, and it is why the estate lives on the declaration.

**2. The estate is declared on the CONTROL, not on the probe.**
`ControlDeclaration::probed()` takes it, required, and all 102 probed declarations in the
tree name one. A probe declaring its own estate would be self-certifying: it would name
the estate of the facts it already requires and then agree with itself. It also could not
express the two cases that made the type necessary. `DataErasureProbe` serves CCPA
1798.105, which is about personal data, and SOC 2 C1.2, which is not; nothing on the
probe can tell those two controls apart, because it is the same probe.
`TamperEvidentAuditProbe` serves eleven controls about the audit trail while the only
fact it measures interrogates a different log.

**3. A fact's estate is derived from its identity.** `ObservationId::subject()` is a total
match with no `default` arm. This is stronger than the design review expected, which had
the estate travelling as a caller-supplied argument inside `src/Compliance/Evidence/`:
`MeasuringComponent` seals who may produce a fact and has never constrained what they may
say, so an estate the gatherer typed would have been one more sentence nothing checks.
Derived, `database_transport_encrypted` is about the database link whichever observer
produces it, and mislabelling it is not expressible. The detail prose stays caller-
supplied, because no type can check it.

**4. Two joins in `ProbeVerdict::reach()`, both refusals.**
_Proof:_ a fact may carry a control only if the control's estate covers the fact's.
_Scope:_ an operator assertion may retire a control only if the estate put out of play
covers the control's. Both are re-checked on the value — in `satisfied()`, `partial()` and
`notApplicable()` — so a second decision table written later meets the same bar.

**5. `covers()` is one level deep, enumerated, and justified per part.**
Flat equality cannot express "the database link is part of data in transit", and the
alternative — naming both transport facts "data in transit" — is the too-broad naming
this ADR exists to refuse. Exactly one estate has parts today and it earns them: HIPAA
164.312(e)(1) and SOC 2 CC6.7 regulate `DataInTransit`, and the two facts that can speak
to it each interrogate one link. The relation is enumerated rather than inferred from
case names, because the case names look like a hierarchy and are not one.

**6. The data classes do NOT nest.** Health data is personal data and cardholder data
usually is. A containment relation saying so would have been true and harmful:
`scope.processes_personal_data = false` would then retire HIPAA 164.312(a)(2)(iv) and PCI
Req 3.4 as well, widening what one line of config can silence. They are siblings, so each
assertion retires exactly the controls whose estate it names.

**7. `TransportSecurityProbe` requires the HTTP transport as well as the database link.**
Every deployment that serves requests has the first, so the control always has something
to be about and fails honestly instead of disappearing. `transport_security_enforced`
stays a config read: TLS for inbound requests is normally terminated in a proxy this
process cannot see, and an observer claiming to have measured it would be a resolution
wearing a Measured badge. Transmission security has to stop evaporating; it does not have
to turn green.

## Consequences

**Nineteen findings moved on the equipped fixture, and the honest report is smaller.**
Satisfied fell from 25 to 11, NotApplicable from 2 to 0, Partial from 3 to 0. Twelve of
the nineteen were carried by `audit_chain_verified` for controls about the audit trail or
the AI management system; two by `health_checks_executed` for controls about the
deployment's configuration; three were Partial verdicts where the "part observed" was
again the generic health checks. Every one was a measurement of something the control
does not regulate.

**Twenty controls can no longer reach Satisfied on ANY deployment shape this release can
build**, which is the honest count and is larger than the nineteen findings that moved on
the fixture — five of the twenty are stuck on shapes the fixture does not build. Counted
against the five facts this release can ever produce at grade Measured, the controls that
could in principle be satisfied fell from 33 to 13. The twenty, per item:

| Count | Controls                                               | Measured fact they rested on   | Its estate                     |
| ----- | ------------------------------------------------------ | ------------------------------ | ------------------------------ |
| 11    | the audit-trail family, DORA-INC-002 through SWIFT 6.4 | `audit_chain_verified`         | `compliance_evidence_register` |
| 1     | ISO 42001 9.2, internal audit of the AI system         | `audit_chain_verified`         | `compliance_evidence_register` |
| 1     | DSA Article 17, in the DSA extension                   | `audit_chain_verified`         | `compliance_evidence_register` |
| 3     | ISO 42001 9.1, 10.1, A.7                               | `health_checks_executed`       | `operational_monitoring`       |
| 2     | ISO 27001 A.8.9, SOC 2 CC5.3                           | `health_checks_executed`       | `operational_monitoring`       |
| 2     | HIPAA 164.312(a)(2)(iv) and its 2026 twin              | `database_transport_encrypted` | `database_transport`           |

The last row of two is worth reading twice, because it is invisible in the fixture: HIPAA's
ePHI ENCRYPTION controls were satisfiable by the database link having negotiated TLS. That
is a false green nobody had noticed, and it only appears on a deployment with a networked,
TLS-negotiating database.

A control stuck in one direction is a broken instrument however comfortable the direction
is, and this is recorded rather than traded quietly: `ControlSubjectReachabilityTest`
proves the stuck state by execution and names the remedy. An observer that writes an audit
event through the bound `AuditSinkInterface` and reads it back — the shape
`TokenVaultObserver` already has for the token vault — makes `ControlSubject::AuditTrail`
measurable and unsticks thirteen of the twenty at once. That observer is the next piece and
gets its own review; this ADR is the reason it is scheduled rather than assumed.

**A report of 89 gaps is only better than 70 if the gaps say more.** They do: a control
whose measurement was about another estate no longer reads like one that was never
measured. Its summary says which estate WAS interrogated and which the control regulates,
and its remediation opens with "Do not re-do that work; it is done" — because an operator
whose sink is bound and whose chain verifies must not be told to bind a sink and record a
verification run.

**What this does not fix**, carried forward unchanged from ADR-0061's own list:

1. The grade-blind "all required present" test in `reach()` survives. An `Observation` at
   Declared or Resolved still fills a required slot; `CapabilityProbe::requirement()` maps
   every required id through `RequiredFact::contributing()`. Deferred to its own decision.
2. `MeasuringComponent` still seals who may produce a fact and not what they may say about
   it. The ESTATE is now beyond that reach — it is derived — but the detail prose is not.
3. "Claimed and not observed" is still spelled `ControlOutcome::Unsatisfied`. There is no
   third column to put it in.
4. Estate granularity is a judgement, and this ADR makes it a reviewable one rather than
   removing it. Naming an estate too broadly walks the original defect back in; the
   defence is that the vocabulary is small, the assignments are in the mapping files where
   the requirement text is, and a misnamed estate shows up as a control that cannot reach
   one of its outcomes.
5. Two controls this repair rescued from NotApplicable — SOC 2 C1.2 and CC6.5 — are now
   assessed and cannot be Satisfied, because this release measures no data disposal at
   all: the erasure fact is a resolution. Being failed by a real gap is the correct report
   and is not the same as being satisfiable.
6. `ConfigurationManagementProbe` requires the health checks and the compliance profile,
   neither of which is about a deployment's configuration. The estate join now says so, and
   the probe's fact list is left for the review that gives it facts about its own estate.
