# ADR-0045: A control status is observed, not written

## Status

Accepted. Deletes and reshapes types carrying `#[Api(since: '1.0.0')]` during the RC
phase, which [ADR-0001](0001-ci-gates-and-adr-discipline.md) asks us to justify rather
than avoid. Closes the gap [ADR-0041](0041-the-token-vault-takes-a-connection.md) left
open in its own consequences section: "It should be closed by checking which store
resolved, not by trusting the mapping."

## Context

ADR-0041 found one control that lied. `PciDssMapping` registered PCI-DSS Req 3.4 as
`ControlStatus::Implemented` and its description named `DatabaseTokenStore` — a class
that could not be constructed and was used nowhere.

An audit has since found the same defect at the level of the standard rather than of one
class. `ControlStatus::Implemented` was written as a literal in eighteen mapping files
under `src/Compliance/Frameworks/` plus two more in `extensions/compliance/`. Counted
across all twenty: **207 controls, 179 of them `Implemented`.** Among them:

- ISO 27001 A.5.1 — an approved, published, acknowledged and reviewed information
  security policy set — graded from the existence of CSP, CORS and rate-limit settings.
- ISO 27001 A.8.25, secure development life cycle, graded from static-analysis
  configuration files being present in the repository.
- SOC 2 CC1.1, "the entity demonstrates a commitment to integrity and ethical values",
  graded from the existence of audit logging.
- NIST CSF RC.RP, incident recovery, a `Partial` literal beside a description of health
  checks and worker restarts. There is no backup or restore primitive in `src/` at all.
- ISO 42001 Clause 9.1, monitoring, `Implemented` on the strength of
  `MonitoringHookInterface` — an interface with **zero implementations** anywhere in
  the repository.

None of it executed. `new ControlCatalog` appeared only in tests and a doc sample; no
wiring built one; `grep -in compliance bin/pulsar` returned nothing. The tests asserted
the literal they had just read: `Iso27001MappingTest` read
`ControlStatus::Implemented` out of the mapping and asserted `ControlStatus::Implemented`
back, so 207 green assertions could only ever detect somebody editing the literal they
were reading. They stayed green through the entire life of the ADR-0041 defect.

## Decision

**A control status must be impossible to write as a literal.**

**1. `Control` and `ControlStatus` are deleted.** A mapping now returns
`ControlDeclaration`s carrying an identifier, the standard's requirement text and a
probe. There is no status parameter anywhere in the declaration API and no public
constructor, so "Implemented" is not a thing a mapping file can say. Deleting the enum
converts all twenty mappings by force of the compiler; keeping it for the sixteen
mappings outside the audit's scope would have left the literal writable, and a
half-enforced invariant here is equivalent to none.

**2. An outcome comes only from a probe, and only from observed behaviour.**
A control cannot be satisfied by configuration, by an operator assertion, or by nothing.
`Partial` is held to the same bar and additionally must name what is still open. See the
amendment below: as first shipped, this rule was enforced only for callers that chose to
go through the checked factory, and adversarial review walked around it.

**2a (amendment). An outcome is inexpressible except as a function of gathered
evidence.** `ProbeVerdict`'s outcome-named factories are private and the only public door
is `ProbeVerdict::reach(ControlRequirement, ControlEvidence)`. `Observation` and
`ControlFinding` have private constructors. A probe returns a `ControlRequirement` —
observation ids, prose and remediations — and never a verdict.

**3. Binding presence is not expressible as evidence.** `ObservationGrade` has no case
for "the class is bound", because that is the same claim as "the class exists", one level
down — the claim ADR-0041 proved worthless. The vocabulary offers resolved _identity_:
`InMemoryTokenStore` and `DatabaseTokenStore` are different facts, and which one answered
is printed in the report.

**4. A probe receives facts, never the container.** `ControlEvidence` is a frozen,
total set of already-graded observations. A probe holding a container would be a service
locator and could ask the cheapest question a container answers. A probe that could read
config could satisfy a control from a value an operator typed.

**5. What the software cannot observe says so.** `ControlDeclaration::operatorResponsibility()`
carries no probe at all — the type enforces it — must name the artefact an assessor is to
be shown, and is excluded from the coverage arithmetic. Twenty-four of SOC 2's
forty-two criteria are now such declarations. That section is the most useful page in the
report for the person doing the audit, and it is the part the old catalogue omitted
entirely by pretending the framework covered it.

**6. Not-applicable requires a signed assertion.** Only an `Asserted` observation
sourced from a named key in `config/compliance.php` can carry `NotApplicable`, and the
report reproduces the key and value attributed to the operator. Silence means in scope.
There is deliberately no key that scopes out a whole standard: enabling a framework IS
the claim that the deployment must satisfy it, so the way to stop being assessed against
one is to stop enabling it, in a diff.

**7. The evidence gatherer is bound as a lazy singleton, and this is not a detail.**
Gathering resolves `TokenStoreInterface`. `SecurityWiring` is eighth in `WiringList` and
`DatabaseWiring` seventeenth, so resolving during wiring resolves before the database
exists — and the container caches singletons, so that answer would stand for the life of
the process. The report would then measure, print and HMAC-chain into the evidence record
a vault the running application does not use. That is ADR-0041's ordering bug, recreated
by the thing built to detect it. It is recorded here rather than only in a docblock
because it is the single most dangerous implementation detail in the design.

**8. Accept lists, not reject lists.** A reject list accepts by default, so the next
null object added is evidence until a reviewer notices; this tree already contains a
`NoopSpanProcessor`. An accept list refuses by default. The cost is taken deliberately:
an implementation this release has never assessed is reported unobserved, naming the
class, rather than assumed adequate.

## Consequences

**The first run is red, and that is the correction.** The default `config/compliance.php`
enables seven frameworks. On a deployment carrying every implementation this release
assesses, gaps remain in SOC 2, HIPAA, SWIFT CSP, NIST CSF and DORA — every one of them
tracing to a primitive that does not exist: no backup and restore contract, no
`DsarStoreInterface` implementation, no `MonitoringHookInterface` implementation. The
red baseline is recorded here, before anyone can relabel it quietly.

Three controls can never be satisfied by any deployment as the tree stands, and each is
a real gap rather than a modelling artefact:

| Control                    | Missing primitive                               |
| -------------------------- | ----------------------------------------------- |
| NIST CSF RC.RP, SOC 2 A1.3 | no backup or restore contract in `src/`         |
| ISO 42001 9.1, A.7, 10.1   | `MonitoringHookInterface` has no implementation |
| CCPA 1798.100              | `DsarStoreInterface` has no implementation      |

RC.RP cannot honestly be scoped out — no deployment can assert that recovery does not
apply to it — so it stays red until someone builds the primitive, installs a package that
provides it, or removes NistCsf from `enabled_frameworks`. Forcing that choice is this
design's job; resolving it is not.

**Reported coverage falls sharply, without a single new gap being introduced.** SOC 2
goes from forty-two counted controls to eighteen, because twenty-four of them were
organizational criteria the framework had never assessed. Probed coverage and checklist
size are reported as separate figures and must stay separate wherever they are printed;
folding them into one percentage is how a catalogue that is mostly checklist comes to
read as mostly covered.

**Public API removed:** `Control`, `ControlStatus`, `ControlVerifier`, `ControlMapping`,
`ComplianceReport`, `ComplianceStatusProvider` and `Pulsar\Compliance\VerificationResult`.
`ControlCatalog` keeps its name and loses `byStatus()` — a catalog cannot know an
outcome. The justification is ADR-0041's own precedent: these classes were constructed
nowhere outside `src/Compliance/`, tests, one doc sample and the API snapshot, so nothing
depended on their shape and preserving it would have been the appearance of compatibility
rather than the thing.

**Size exemption.** Deleting `ControlStatus` converts all twenty mapping files at once,
which exceeds [ADR-0031](0031-pull-request-size-limits.md)'s limits. The
staged alternative means shipping at least one release in which the literal is still
writable. The exemption is taken deliberately; the bulk conversion of the sixteen
mappings outside the audit's scope is mechanical and reviewable by category
(organizational → operator responsibility, technical → shared probe) rather than control
by control. The alternative failure mode is worse: four exemplary mappings beside sixteen
with literals is a codebase that teaches both patterns at once.

**Softest joint.** Nothing in the type system can tell whether a gatherer method measured
something or read a config value. What keeps it honest is concentration — every grade in
the system is assigned in about a dozen methods of one class, not across two hundred
controls — and exposure: each observation carries the class that produced it, printed
beside its grade, so `measured … (SecurityPostureCheck)` reads wrong on the page. This
remains unsolved by construction and is the thing to watch in review.

**Guarded by a total test.** `ProbeAdmissibilityTest` runs every probe in the tree —
enumerated from the directory, not the catalog, so an unwired probe is still held to the
rule — against an all-absent evidence set and an all-`Declared` one, and asserts that
neither can produce `Satisfied`. It is cheap, total, and fails the moment someone adds a
probe that concludes from intent. Writing it immediately caught `PanAtRestProbe`, whose
hand-rolled branches would have reached `Satisfied` from configuration had
`ProbeVerdict` not refused it.

## Amendment (1.0.0-rc.12): the same defect, inside the cure

Adversarial review reproduced ADR-0041's defect inside the subsystem built to make it
inexpressible. Three expressions did it, and none of them needed a mapping file:

```php
new ControlFinding(declaration: $d, outcome: ControlOutcome::Satisfied,
    summary: 'Implemented.', probeId: 'probe.pan_at_rest');   // satisfied, zero evidence

new Observation(AuditChainVerified, ObservationGrade::Measured, present: true,
    detail: 'Implemented.', observedBy: self::class);          // a probe minting its own proof

ProbeVerdict::satisfied('Implemented.', $observationsIJustMinted);
```

The decision above was written as a rule about what a caller _should_ pass. What review
demonstrated is that a rule of that shape is not a rule: `ControlFinding` was `#[Api]`
with a public constructor whose second parameter was a `ControlOutcome`, so the status
literal ADR-0045 deleted from twenty mapping files had simply moved one class over. The
correction is structural rather than procedural.

**The rule.** No public constructor or factory anywhere in `Pulsar\Compliance\Control`
accepts a `ControlOutcome`, an `ObservationGrade`, or a `present` flag from its caller.
Not discouraged — absent from every signature, and asserted to be absent by a test that
enumerates the namespace.

**How each type is sealed.**

| Type                    | Was                                          | Is                                                                                           |
| ----------------------- | -------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `Observation`           | public constructor taking grade + `present`  | private constructor; named constructors take the material of one kind of act and derive both |
| `ProbeVerdict`          | public `satisfied()`/`partial()`/…           | private constructor and private outcome factories; the only door is `reach()`                |
| `ControlFinding`        | public constructor taking a `ControlOutcome` | private constructor; the only door is `assess(ControlDeclaration, ControlEvidence)`          |
| `ControlProbeInterface` | `observe(ControlEvidence): ProbeVerdict`     | `requirement(): ControlRequirement` — ids and prose, no evidence in, no outcome out          |

`Observation`'s named constructors are where "derived, not supplied" is cashed out.
`measured()` takes a `Measurement`, which cannot exist without naming at least one
`ExecutedSubject` that ran — so grade `Measured` about something never measured throws
rather than reporting. `resolved()` takes a `ContractResolution`, which reads the class
that answered and matches it against the accept list. `inspected()` takes an
`Inspection`, whose `coverage()` refuses an empty population, because "every classified
route carries its middleware" over zero classified routes prints identically to real
coverage. The `declared*` and `asserted*` pairs still record what was read or claimed:
neither grade proves behaviour, so neither can carry a control however it is spelled.

**Two decision-table defects went with it.** Admissibility was judged over
required-plus-supporting observations, so a single admissible _supporting_ fact — a
corroborating detail that decides nothing — could carry a control to `Satisfied` on its
own; it is now judged over the required facts only. And `CapabilityProbe::satisfiedSummary()`
was a hard-coded constant per subclass, asserting in prose what the evidence printed
beneath it could contradict — the status literal restored as a sentence. It is deleted
from all thirty-three probes; the summary is generated from the observations that
decided the verdict and names each of them.

**What remains unsealed, stated plainly.** Whoever gathers facts must ultimately say what
the deployment did, and no type can check that against reality — the softest joint noted
above is unchanged. What is now closed is everything downstream of it: a probe cannot
mint an observation into a verdict, because verdicts are computed by the engine from the
evidence set the engine holds.

**Guarded by an adversarial test.** `OutcomeSealTest` reproduces all three expressions
above verbatim and asserts each one fails, then asserts the general rule over every class
in the namespace, so the next type added is held to it without anyone remembering to.
The previous guard, `ProbeAdmissibilityTest`, was green through all three.

**Public API changed:** `Observation::__construct` and `ControlFinding::__construct` are
private; `ControlFinding::fromVerdict()` and `ControlFinding::operatorResponsibility()`
are replaced by `ControlFinding::assess()`; `ProbeVerdict`'s four outcome factories are
private and `ProbeVerdict::reach()` is added; `ControlProbeInterface::observe()` is
replaced by `requirement()`; `CapabilityProbe::satisfiedSummary()` is removed. Added:
`ControlRequirement`, `RequiredFact`, `Measurement`, `ExecutedSubject`, `Inspection`,
`ContractResolution`, `UnmeasuredSubjectException`. The justification is the one this ADR
already took: preserving a shape whose whole purpose was to let a caller write an outcome
would be the appearance of compatibility rather than the thing.

## Amendment (second review): resolved identity is not the floor either

The seal above closes the question of who may _write_ an outcome. Review then asked the
adjacent question — what an outcome may be written _from_ — and found the answer still
too weak in one place, and pointed at the wrong subject in several others.

**"The class is bound" is "the class exists" with a longer sentence.** ADR-0041 concluded
that PCI Req 3.4 "should be closed by checking which store resolved, not by trusting the
mapping". That was done, and on this very repository it produced a green control over a
vault that cannot hold a token: `TokenStoreInterface` resolves to `DatabaseTokenStore` —
the durable implementation the ADR prescribed, matched against the accept list, graded
`resolved` — against a database with no `token_vault` table in it. Every `tokenize()`
throws. The identity fact is correct, present, and worthless on its own.

So where a control turns on a subsystem _working_ rather than on which implementation
serves it, the deciding fact must be `measured`. `TokenVaultObserver` tokenizes a
synthetic value through the live vault, reads the persisted bytes back **from the store
rather than through the service that wrote them**, checks they conceal the input,
detokenizes it, and removes the mapping again. It is the only measurement in the evidence
set that writes; the value is 32 random hex characters, the context is
`compliance.vault_probe` rather than `pan`, removal runs in a `finally`, and a removal
that fails is reported as a failed subject rather than left silent. A compliance report
that refuses to touch the subject it reports on is the artefact ADR-0041 was written
about.

The same reasoning replaced ISO 27001 A.8.24's probe. "Including cryptographic key
management" was being answered by a `MasterKey` object existing in the container, which
proves a constructor ran; it is now answered by `runtime.master_key_derived`, which runs
the KDF against the key in service and asserts the subkey length, reproducibility, and
domain separation across contexts.

**A control may not be decided by facts about a different subject.** PCI Req 2.3 declared
non-console administrative access and was decided by the database session and the session
cipher — a deployment on SQLite reached `Satisfied` on the sentence "SQLite is a local
file; the session has no network transport to encrypt". ISO 27001 A.8.1 declared user
endpoint devices and was decided by the session cipher and cookie flags. SWIFT CSP 2.1,
2.4A and 2.5A declared the SWIFT secure zone and were decided by this application's own
database link. PSD2 Art 66 and 67 oblige an institution to _grant_ third-party access and
were decided by route middleware coverage, which measures keeping requests out. Each is
now an operator-responsibility control naming the artefact an assessor should demand.
Removing them from the coverage denominator is the point: a colour reported about
something else is worse than no colour.

**Two more rules, each stated where a reader meets it.**

- A control wider than anything software can see is probed for the part that can be
  observed, says so in its requirement text prefixed `ASSESSED NARROWLY:` — the text the
  report prints — and declares the residual as its own operator-responsibility control.
  PCI Req 3.4 names Req 1.1.3; A.8.24 names A.5.1.
- `ControlDeclaration::$frameworkFeatures` is removed. It was a list of identifiers a
  mapping author typed — `['tokenization', 'token_vault', 'crypto_keyring']` beside Req
  3.4 — that nothing derived, nothing checked and nothing could falsify, printed by the
  JSON renderer beside the evidence as though the two were the same kind of thing. It was
  ADR-0041's "Covered by TokenizationService" in list form. What covers a control is the
  observations that decided it.

**A mapping nothing registers is not a mapping.** `DsaMapping` and `DataActMapping`
declared sixteen controls between them and were referenced by nothing outside their own
class declarations — no provider, no wiring, no boot hook — so their controls could
neither be reported nor falsified. Both extensions now register their declarations from
`boot()`, conditionally on `ControlCatalog` being bound, which is after
`ComplianceCatalogWiring` has run and before any report can be asked for.

**Public API changed:** `ControlDeclaration::probed()` no longer takes
`$frameworkFeatures`, and `ControlDeclaration::$frameworkFeatures` and the JSON report's
`framework_features` key are removed. `EncryptedAdministrativeAccessProbe` is deleted with
the control it served. Added: `TokenVaultObserver`, `KeyManagementProbe`, and the
`ObservationId` cases `TokenVaultRendersUnreadable` and `KeyDerivationVerified`.
`ControlEvidenceGatherer` takes a `TokenVaultObserver` and the two vault services.

**Guarded by:** `PciDssMappingTest::aBoundButUnusableVaultDoesNotSatisfyPanAtRest()`
stands up a real `DatabaseTokenStore` over a database with no table and asserts the
control is a gap; it fails against any probe that reads only which class answered. Its
sibling asserts `Satisfied` only where the vault actually round-trips.
