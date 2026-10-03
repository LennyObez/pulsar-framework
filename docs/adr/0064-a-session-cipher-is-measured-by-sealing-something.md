# ADR-0064: A session cipher is measured by sealing something

## Status

Accepted. Adds one `#[Api(since: '1.0.0-rc.12')]` interface to the Security module
(`SessionPayloadCipherInterface`), one `#[Api]` observer to the Compliance module
(`SessionSealObserver`) and one case to the `ObservationId` vocabulary
(`SessionPayloadsSealed`). All three are additive, which is what
[ADR-0001](0001-ci-gates-and-adr-discipline.md) asks for during the RC phase.
Continues [ADR-0041](0041-the-token-vault-takes-a-connection.md),
[ADR-0061](0061-a-loaded-extension-is-not-a-measurement.md) and
[ADR-0062](0062-proof-must-be-about-the-control-subject.md).

## Context

ADR-0061 demoted `extension_loaded('sodium')` from Measured to Available, on the
ground that the reading is identical on a deployment that encrypts every field and
on one that encrypts nothing. It was right, and it left nine controls across seven
frameworks resting on nothing at all: CCPA 1798.150, GDPR Art 5(1)(f) and Art 32,
HIPAA 164.312(a)(2)(iv) and its 2026 twin, ISO 27001 A.8.24, NIS2 Art 21(h), NIST
CSF PR.DS and PCI Req 3.4.

The fact that was supposed to answer for session payloads did not help. It is
`session_encryption_resolved`, and what it establishes is that a
`SessionEncryption` was constructed and bound — the claim ADR-0041 showed to be
worthless, one layer down. Grade Declared, so it cannot carry a control, so SWIFT
CSP 2.6 (operator session confidentiality and integrity) could not be Satisfied on
any deployment shape at all.

The gatherer said in its own source why it could do no better:

> Nor can it be measured from here — exercising the cipher means holding one, and
> `SessionEncryption` is `#[Internal]` to the Security module, so importing it
> would break the boundary the composition root exists to keep.

That is a true statement about a module boundary and a false statement about what
is possible. **Demoting a false green is half a repair.** This catalogue has stood
at both ends of that: 89 of 100 assessed controls read "claimed and not observed"
after ADR-0062, and 32 read a false green before ADR-0061. The second report is
worse, and the first carries little more information than it — operators stop
reading an instrument like that. The other half is measuring the thing.

## Decision

**1. The Security module publishes the two operations, and keeps everything else.**
`SessionPayloadCipherInterface` declares `encrypt()` and `decrypt()`, each taking
the session context the sealed form must be bound to. It publishes no key, no key
identifier, no algorithm and no envelope layout; key rotation and the key ring stay
inside `SessionEncryption`, which remains `#[Internal]`. This is the shape
`TokenizationServiceInterface` already has for the token vault, taken for the same
reason.

Two clauses of the contract are obligations rather than descriptions, and both are
measured: `decrypt()` MUST refuse modified bytes, and MUST refuse a context other
than the one the payload was sealed under.

**2. `SessionSealObserver` runs four subjects against the live cipher.**

1. seal a synthetic payload — the sealed form must not carry it, as it stands **or
   once base64-decoded**
2. open it under the same context — the payload must come back byte for byte
3. change one byte of the sealed form — opening it must be refused
4. offer the same bytes as a different session — that must be refused too

Subject 1 alone is not a seal. Opaque output that is not authenticated can be
edited by whoever holds the storage; opaque output that ignores its session context
can be lifted from one session into another, which is the transplant attack
`SessionEncryption` names in its own class docblock and which nothing checked.
Subjects 3 and 4 are those two, and a cipher can fail either while passing 1. The
decoded check in subject 1 is what catches a "cipher" that base64-encodes the
plaintext, which passes a naive `str_contains` and stores the session in the clear.

**3. The measurement writes nothing, and the difference from `TokenVaultObserver`
is principled.** The vault's at-rest form lives in a store the service writes
_through_, so nothing short of a write and a read-back shows what the persisted
bytes look like. The session cipher _returns_ the at-rest form: the bytes handed
back are the bytes the handler would store. No session file, row or cookie is
created, nothing is written under a synthetic session id, and there is no cleanup
that can fail.

**4. Absence is reported absent, never subjectless.** A deployment with no session
cipher has not escaped the question — it has session payloads and they reach the
handler exactly as the application left them. `Observation::noSubject()` would take
the control out of the coverage denominator, which is how "there is no transport to
encrypt" once satisfied an encryption control (ADR-0062, defect A5). So the run is
reported as one that could not happen, which observes absent, and the control
fails.

**5. The estate is `SessionPayloads` and nothing wider.** The fact carries the one
control declared over that estate and does not carry the personal-data,
health-data or confidential-information controls whose probes now require it.
Naming it one level wider — `DataAtRest` — would make it carry them, and that is
`extension_loaded('sodium')` with a longer name. Consequence stated below.

**6. `SecurityWiring` binds the cipher after the crypto block completes.** It used
to bind at the point of construction, so a failure _later_ in the same `try` — the
tokenization service, the framework cache — was caught, nulled the local, and left
the container holding an instance the `SessionManager` was never given. The
deployment would then write cleartext while the posture check, the runtime verifier
and now this observer all found a working cipher. The catch's own log line already
said "sessions are written in cleartext"; the container now agrees with it. This is
not incidental: the observer's honesty depends on the object it is handed being the
object in the write path.

## What moved

Recorded by running the assessment, before and after, against the real composition
root:

| Deployment                 | Before                                       | After           |
| -------------------------- | -------------------------------------------- | --------------- |
| `withNothing`              | operator_responsibility 115, unsatisfied 100 | unchanged       |
| `fullyEquipped`, swift_csp | 2 satisfied                                  | **3 satisfied** |

**Exactly one control moved: SWIFT CSP 2.6, from Unsatisfied to Satisfied.** It is
the only control in the catalogue declared over `ControlSubject::SessionPayloads`.
Nothing moved on a deployment that has nothing, which is correct — a deployment
with no cipher seals nothing.

`DataProtectionAtRestProbe` and `HealthDataProtectionProbe` gained the fact as a
REQUIRED one and their controls' outcomes did not change, because the estate join
holds it aside for them. It was added anyway, per-item, for the failing direction:
before it, a deployment that bound a cipher storing payloads in the clear reported
exactly what a deployment with a sound cipher reported. Now the gap is named and
carries a remediation. `SessionSealObserverTest` builds that deployment — bound,
constructible, `session_encryption_resolved` present, and base64 for a seal — and
proves the control fails on it.

## What this does not fix

Carried forward, with what this repair touched marked:

1. **The grade-blind "all required present" test in `reach()` survives.** TOUCHED:
   `SecureSessionProbe` requires the measured seal _and_ the Declared
   `session_encryption_resolved`, and the second fills a required slot while
   proving nothing. That pairing is deliberate — the Declared fact is what says the
   cipher exercised is the cipher in the write path — but it is the residue, not a
   fix for it. Deferred to its own decision.
2. `MeasuringComponent` still seals who may produce a fact, not what they may say.
   The estate is derived and beyond that reach; the detail prose is not.
3. "Claimed and not observed" is still spelled `ControlOutcome::Unsatisfied`.
4. **Estate granularity.** TOUCHED, and chosen narrow: `SessionPayloads`. The cost
   is item 6 below and it is paid deliberately.
5. Flat subject equality still cannot express a session as part of a wider estate,
   and deliberately: making `PersonalData` contain `SessionPayloads` would let a
   sealed session answer for a database full of records.
6. **Controls that can never be Satisfied.** GDPR Art 5(1)(f), CCPA 1798.150, NIST
   CSF PR.DS and HIPAA's two encryption controls remain unsatisfiable on every
   deployment shape this release can build. This ADR does not close that and does
   not pretend to: it closes the one control whose estate is the one that was
   measured. The remedy is an observer for each of _those_ estates, which is a
   different piece of work from renaming this one.

**What is not deferred and was refused outright:** no measured observer for HTTP
transport security. TLS termination usually lives at a proxy this process cannot
see, so a "measured" fact there would be a resolution wearing a Measured badge —
the exact defect ADR-0061 exists to remove. Transmission security must stop
evaporating into NotApplicable (ADR-0062 did that); it does not have to become
Satisfied, and an honest Unsatisfied is the goal.
