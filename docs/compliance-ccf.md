# Common Control Framework (CCF)

Pulsar declares regulatory controls, and computes each one's outcome from the deployment it is run against.

> **Disclaimer**: This system documents observed control coverage for one deployment at one moment. It does not constitute a compliance certification. Compliance is an organizational responsibility that extends beyond technical controls.

## The rule the design is built on

**A control status cannot be written as a literal.**

A mapping declares a control's identifier, the standard's requirement text, and a _probe_. The outcome is the probe's return value, computed against the booted container. There is no status parameter anywhere in the declaration API, so "Implemented" is not something a mapping file can say.

That rule exists because of a defect recorded in [ADR-0041](adr/0041-the-token-vault-takes-a-connection.md): PCI-DSS Req 3.4 was registered as `Implemented`, and its description named a class that could not be constructed and was used nowhere. A control that reports itself implemented on the strength of code existing is worth less than no control at all.

## Architecture

### Control declarations

A mapping returns declarations. There are exactly two kinds, and each fixes what its control may rest on.

```php
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\PanAtRestProbe;

ControlDeclaration::probed(
    id: 'Req3.4',
    framework: ComplianceFramework::PciDss,
    title: 'Render PAN Unreadable Anywhere It Is Stored',
    requirement: 'Render PAN unreadable anywhere it is stored by using strong one-way '
        . 'hash functions, truncation, index tokens, or strong cryptography.',
    probe: new PanAtRestProbe(),
);
```

A control that is discharged outside the software — an approved policy, a signed breach register, a training record, a CI run for the deployed commit — carries **no probe at all**. It must name the artefact an assessor is to be shown, and it never counts toward coverage:

```php
ControlDeclaration::operatorResponsibility(
    id: 'A.5.1',
    framework: ComplianceFramework::Iso27001,
    title: 'Policies for Information Security',
    requirement: 'Information security policy and topic-specific policies shall be defined, '
        . 'approved by management, published and reviewed at planned intervals.',
    artefact: 'The approved information security policy set, with its management approval '
        . 'record, publication date and the date of the last review.',
);
```

The requirement text is the standard's own words. Prose asserting which Pulsar class does the work is exactly the claim ADR-0041 found to be false, and it is now the probe's job to find out.

#### When a control is wider than anything software can see

Some controls have an observable part and an unobservable one. PCI Req 3.4 says PAN must be unreadable **anywhere it is stored**, and Pulsar knows about exactly one storage location: the token vault it ships. ISO 27001 A.8.24 asks for cryptographic rules to be **defined and implemented**, and only the second half is a question about a running process.

Neither may be declared as though the whole control were assessed. The convention is:

1. Probe the part that can be observed, and probe it properly.
2. State the narrowing **in the requirement text**, prefixed `ASSESSED NARROWLY:`, because that is the text the report prints and the place a reader looks.
3. Declare the residual as its own operator-responsibility control, naming the artefact that closes it, and point at it from the narrowing.

PCI Req 3.4 names Req 1.1.3 (the cardholder data flow diagram and storage inventory); A.8.24 names A.5.1 (the approved policy set). A narrowed control that does not say it is narrowed is an overstated one.

#### When the probe would observe a neighbour

A control whose only available facts are about a _different_ subject does not become a probed control by being probed anyway. Four of these were found and moved to the operator checklist rather than left green:

| Control                                         | Was decided by                          | Its actual subject                                                                 |
| ----------------------------------------------- | --------------------------------------- | ---------------------------------------------------------------------------------- |
| PCI Req 2.3, encrypt non-console admin access   | the database session and session cipher | the channel an administrator's traffic crosses, terminated outside the PHP process |
| ISO 27001 A.8.1, user endpoint devices          | the session cipher and cookie flags     | laptops and phones                                                                 |
| SWIFT CSP 2.1 / 2.4A / 2.5A, data flow security | this application's own database link    | the SWIFT secure zone                                                              |
| PSD2 Art 66 / 67, third-party provider access   | route middleware coverage               | _granting_ access, which is the opposite obligation                                |

Moving a control to the checklist removes it from the coverage denominator. That is the point: it stops a colour being reported about something else.

### Observations, and how they were obtained

A probe never touches the container. It reads a frozen set of already-gathered facts, each carrying its **grade** — how the fact was obtained.

| Grade      | Meaning                                                                                                         |
| ---------- | --------------------------------------------------------------------------------------------------------------- |
| `measured` | The behaviour was exercised: an algorithm ran, an HMAC chain verified                                           |
| `resolved` | The concrete implementation that will serve requests was resolved and named. Context in the report; never proof |
| `declared` | A configuration value was read: what was requested, never what happened                                         |
| `asserted` | The operator stated a fact about the deployment that no code can observe                                        |

**Only `measured` proves behaviour.** `resolved` did too until [ADR-0050](adr/0050-a-fact-is-produced-only-by-the-component-that-measures.md), and that was ADR-0041's defect restated in the vocabulary built to forbid it: what a resolution computes is `in_array($concrete, $accepts)` — which class is bound, and is it on the allow-list — which is "the class exists" with one extra lookup.

`resolved` is still produced and still printed, because a report saying `TokenStoreInterface -> InMemoryTokenStore; tokens are held in process memory` tells an assessor which class to go and look at. It is context, carried with its grade beside it, and never the reason a control holds. Note what is absent from the enum: there is no grade for "the binding exists" either. The vocabulary offers resolved _identity_ and nothing weaker.

Narrowing the grade cost real coverage and the cost was taken rather than engineered around: on a deployment carrying every implementation this release assesses, probed coverage fell from 78/96 to 32/96. None of the 46 that moved got worse — they were passing on which class was wired. Their findings now read "Claimed and not observed", name the class that was found, and carry a remediation saying what would close them.

### Absence is not presence

An observation says whether the desirable state **holds**, and it has a third answer besides yes and no: **this deployment has no subject for the fact**. The evidence column prints it as `no subject`.

The third state exists because two states forced a lie. A deployment with no database at all had to answer the database-transport-encryption fact one way or the other, and it answered `present: true` — "there is no transport to encrypt" — so a deployment that encrypts nothing carried PCI Req 2.3. A SQLite-only deployment did the same at grade `measured`, on the sentence "SQLite is a local file; the session has no network transport to encrypt".

A subjectless fact is produced from a `SubjectAbsence`, which **refuses to exist over a population that has a member**: the observer must hand it the enumeration it performed, and an absence claimed while subjects are standing there throws. Downstream it decides nothing in either direction — it cannot prove a control, and it is not counted against one.

| Required facts                           | Outcome                                           |
| ---------------------------------------- | ------------------------------------------------- |
| every one reports no subject             | `not_applicable` — nothing to be about            |
| one subjectless, another observed        | the observed one decides                          |
| one subjectless, another only configured | still a gap — an absent neighbour excuses nothing |

`not_applicable` is neither a failure nor a pass: it does not enter the coverage denominator, and `compliance:check` does not fail on it. A control is never excused by the absence of something it does not need, and never excused by the absence of a neighbour it does.

### Ok is not the same question as "does the control hold"

`SecurityPostureCheck` answers "should this deployment be stopped over it?", and outside production it answers no for weaknesses that plainly exist. Those items are `Ok` **and** carry `relaxed: true`; the evidence gatherer reads the flag, not just the status, and reports the control as unmet. A local deployment with debug mode on used to publish `debug_mode_disabled / present: true` beside the reason "Debug mode is enabled".

**And resolved identity is not always enough.** ADR-0041 prescribed checking which store resolved for PCI Req 3.4, and doing exactly that left the next layer open: on the repository this was written in, `TokenStoreInterface` resolves to `DatabaseTokenStore` — the durable implementation the ADR asked for — against a database holding no `token_vault` table, so the first `tokenize()` throws and nothing is ever rendered unreadable. Where a control turns on a subsystem _working_ rather than on which implementation serves it, the fact must be `measured`. `TokenVaultRendersUnreadable` is such a fact: it tokenizes a synthetic value through the live vault, reads the persisted bytes back from the store, checks they conceal the input, detokenizes it, and removes the mapping again.

That measurement **writes**, and it is the only one in the evidence set that does. The value is 32 random hex characters from the CSPRNG, never a PAN; the context is `compliance.vault_probe`, never `pan`; removal runs in a `finally`; and a removal that fails is reported rather than left silent.

### Probes

A probe declares which facts its control rests on and how they combine. It is handed no evidence and returns no outcome, so it can neither read the deployment nor state a conclusion about it.

```php
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\RequiredFact;

public function requirement(): ControlRequirement
{
    return ControlRequirement::of(
        required: [
            RequiredFact::essential(
                ObservationId::TokenVaultPersistence,
                ['Configure a database connection so TokenStoreInterface resolves to DatabaseTokenStore.'],
                'PAN tokens are not held in a store that survives a restart',
            ),
            RequiredFact::contributing(
                ObservationId::CryptographicCapability,
                ['Install ext-sodium and deploy against OpenSSL providing AES-256-GCM.'],
            ),
        ],
        scope: ObservationId::ScopeStoresCardholderData,
    );
}
```

`ProbeVerdict::reach()` then applies one decision table against the evidence set the engine gathered:

| Evidence                                                    | Outcome                             |
| ----------------------------------------------------------- | ----------------------------------- |
| the scope assertion puts the subject out of play            | `not_applicable`                    |
| every required fact present, one of them `measured`         | `satisfied`                         |
| every required fact present, none of them `measured`        | `unsatisfied` (claimed, unobserved) |
| an `essential` fact missing                                 | `unsatisfied`                       |
| another required fact missing, one required fact `measured` | `partial`                           |
| another required fact missing, none `measured`              | `unsatisfied`                       |

Admissibility is judged over the **required** facts only. Supporting facts are printed with the finding and named in the summary as corroboration; they never decide it.

Both summaries are generated from the observations that decided the verdict and print the grade each was obtained at, so the sentence at the top of a finding is checkable against the lines under it. There is no per-probe summary constant: a sentence asserting the control holds would be prose no evidence had to support.

A claimed-and-not-observed finding also carries a generated remediation ahead of the declared ones. The declared remediations answer a different question — what to do when a fact is _absent_ — and printed alone under this outcome they read "bind X" about something already bound. The generated line names the facts, names the grade each was obtained at, and says the two things that close the control: an observer that puts the subsystem through its work, or the framework removed from `enabled_frameworks`.

### The seal

Every class under `src/Compliance/Control/` has a **private constructor**, and every named constructor that produces a fact **refuses a caller that is not the component that measures**. That is decided by the file the calling class is compiled from — `src/Compliance/Evidence/` — not by its name and not by its namespace, because a namespace is something any file anywhere can declare.

The honest limit, stated because three earlier designs claimed more than they delivered: **PHP has no package-private visibility.** A member is private to a class or protected to a hierarchy, and nothing else. Private constructors alone left every material type reachable through public `#[Api]` factories, so `Observation::measured($id, Measurement::completed('x', [ExecutedSubject::passed('x', 'ok')], 'x'), self::class)` produced the strongest evidence in the system about a deployment nobody looked at — one expression, no container, no deployment, no measurement. Narrowing signatures moves that lie one call deeper and never removes it.

`__wakeup`, `__unserialize`, `__set_state` and `__clone` all refuse as well: a private constructor settles who may call a value into existence and settles nothing about the ways PHP hands you one without a constructor.

Reflection still reaches the private constructors, deliberately. The tests build fixtures that way, through a single file that says what it is, because a fixture that could be built through the API would mean a fabricated compliance report could be too.

Probes are shared, named objects rather than closures on a mapping: ISO 27001 A.8.15, PCI Req 10.2 and NIST DE.AE all want the same fact about audit logging, so they cite one measurement instead of three pieces of prose that can drift apart.

### Outcomes

| Outcome                   | Meaning                                                            | Counts toward coverage |
| ------------------------- | ------------------------------------------------------------------ | ---------------------- |
| `satisfied`               | Observed working in this deployment                                | yes                    |
| `partial`                 | Partly observed; the residual gap is named in the remediations     | yes                    |
| `unsatisfied`             | An enabled framework claims it and the deployment does not show it | yes                    |
| `not_applicable`          | Out of scope, on an operator assertion reproduced with its key     | no                     |
| `operator_responsibility` | Discharged outside the software                                    | no                     |

Coverage is always reported as two figures — probed coverage and checklist size — never folded into one percentage. A catalogue that is mostly checklist must not read as mostly covered.

### Operator scope assertions

Nothing in the tree records which data classes a deployment handles, and no code can derive it. A `scope` block in `config/compliance.php` carries the operator's assertions, and they are admissible for exactly one thing: marking a control not applicable.

```php
// config/compliance.php
'scope' => [
    'stores_cardholder_data' => false,
    'processes_health_data' => true,
    'processes_personal_data' => true,
],
```

Silence means **in scope**. Scoping a control out takes an explicit `false` under a named key, and the report reproduces that key and value attributed to the operator, so an assessor can falsify it in one question.

## Running an assessment

`ComplianceCatalogWiring` binds the catalog, the assessment and the evidence gatherer, and **none of the three is built at boot**.

The catalog holds deferred _sources_ — the framework's own sixteen mappings, plus one per installed compliance extension — and executes them at its first read. Nothing on the request path reads it, so no request pays for the sixteen mapping classes or the 193 declarations behind them; measured on the reference machine, building it eagerly cost 0.52 ms warm and roughly 22 ms cold on every boot of every application, compliance-enabled or not. The evidence gatherer is lazy for a different and stronger reason: gathering opens a database session, executes every registered health check and tokenizes a value through the live vault, and doing that during wiring would observe services before the wirings that replace them had run.

An extension contributes its controls with `contribute()` rather than `register()`, so its mapping is autoloaded at the first read too:

```php
public function boot(ContainerInterface $container, RouterInterface $router): void
{
    if ($container->has(ControlCatalog::class)) {
        /** @var ControlCatalog $catalog */
        $catalog = $container->get(ControlCatalog::class);
        $catalog->contribute(static fn(): array => MyMapping::declarations());
    }
}
```

Contribute during boot. A source handed over after the catalog has been read is refused with a `CatalogAlreadyBuiltException`: it would declare controls that every report produced up to that point silently omitted, and an artefact missing a control looks exactly like one whose controls are all present.

```php
$catalog = $container->get(ControlCatalog::class);
$assessment = $container->get(ControlAssessment::class);
$evidence = $container->get(ControlEvidenceGatherer::class)->gather();

$findings = $assessment->assessFrameworks($config->enabledFrameworks, $evidence);
$summary = ControlAssessment::summarize($findings);
```

Findings come back worst-first within each framework, each carrying the probe that concluded it, the observations it rests on (with their grades and the class that produced each), and what is still open.

## Supported frameworks

Sixteen mappings ship in `src/Compliance/Frameworks/`, and two more in the DSA and Data Act extensions:

| Framework | Mapping           | Framework | Mapping           |
| --------- | ----------------- | --------- | ----------------- |
| SOC 2     | `Soc2Mapping`     | ISO 42001 | `Iso42001Mapping` |
| HIPAA     | `HipaaMapping`    | HL7 FHIR  | `Hl7FhirMapping`  |
| GDPR      | `GdprMapping`     | MDR       | `MdrMapping`      |
| PCI DSS   | `PciDssMapping`   | ISO 13485 | `Iso13485Mapping` |
| NIS2      | `Nis2Mapping`     | DORA      | `DoraMapping`     |
| ISO 27001 | `Iso27001Mapping` | SWIFT CSP | `SwiftCspMapping` |
| PSD2      | `Psd2Mapping`     | CCPA      | `CcpaMapping`     |
| eIDAS     | `EidasMapping`    | NIST CSF  | `NistCsfMapping`  |

The sixteen core mappings are contributed by `ComplianceCatalogWiring` as a single deferred source. The two extension mappings contribute themselves from their extension's `boot()`, in the form shown under [Running an assessment](#running-an-assessment).

Boot is the right moment and the only one: `ComplianceCatalogWiring` is the last entry in the wiring list, so the catalog exists by the time extensions boot, and every source is in place before anything can read it. The check on the binding keeps a MicroKernel deployment — which wires no compliance at all — working rather than fatal.

`DsaMapping` and `DataActMapping` previously had no registration anywhere: no provider, no wiring, no boot hook. Their sixteen controls could not be reported and could not be falsified, which is ADR-0041's defect with the failure mode moved from wrong to silent. **A mapping that nothing registers is not a mapping.** If you add one, register it and assert in a test that the catalog holds it.

## Evidence collection

Evidence records are separate from control assessment: they are the chained, HMAC-signed history of verification runs.

You do not build this yourself. `ComplianceVerificationWiring` constructs the chain over a durable `FileEvidenceStore` (beside the audit trail, or in-memory when audit logging is off), keys it from the master key under `SubKeyId::ComplianceEvidenceChain`, and hands it to the verification engine. Every run of the engine appends one signed record whose signature covers the previous record's, the record's own position in the chain, and every field an auditor reads a verdict off — and then rewrites the register's **anchor**, a small signed file beside it stating how many records the chain has written.

The anchor is what makes removal detectable. A hash chain proves that no record still present has been altered or moved; it proves nothing about records deleted from the end, because what remains is a shorter chain in which every surviving link still verifies. Only an out-of-band commitment to the height can see that, which is what `EvidenceChainHead` is. Archive the register and its `.head` file together — a register moved without its anchor reads as truncated, which is exactly what it would look like if it had been.

Ask the chain what the register is; do not hand it a list of records:

```php
$chain = $container->get(EvidenceChain::class);

$result = $chain->verify();

$result->verdict;   // EvidenceChainVerdict::Intact
$result->present;   // 42 records found
$result->attested;  // 42 attested by the anchor
$result->verified;  // 42 signatures recomputed and held
$result->summary;   // one sentence for the operator
```

`verify()` reads the store itself — its records, its own report of whether the medium is fully readable, and its anchor. It reports one of eight distinguishable findings, because they are different findings and an assessor needs them apart:

| Verdict          | What was found                                                                                              |
| ---------------- | ----------------------------------------------------------------------------------------------------------- |
| `Intact`         | Every attested record present, in order, each authenticating and chained. The only admissible case.         |
| `Empty`          | Nothing in the register. An empty chain is not a verified one.                                              |
| `Modified`       | A record that is present was rewritten where it lies, or does not chain to the one before it.               |
| `Truncated`      | Records the chain committed to are not there — from the end, from the middle, or edited beyond recognition. |
| `Reordered`      | Every record present, not in the order the chain wrote them (a repeated position counts).                   |
| `Unreadable`     | A line the store could not decode, or an anchor that is missing, garbled, or does not authenticate.         |
| `Unanchored`     | The store cannot state the chain's height, so removal from the end could not be checked at all.             |
| `KeyUnavailable` | The register was signed under a key this process does not hold.                                             |

Collection happens on the interval `config/compliance.php` sets in `verification.evidence_interval`, driven by the `compliance:collect-evidence` scheduled job — so the trail needs `pulsar scheduler:tick` running. The boot check deliberately does **not** record: under PHP-FPM the kernel boots once per request, and one signed record per request is not evidence of anything.

There was previously a second writer here, `EvidenceCollector`, which nothing wired and which produced records with `signature: null` into the same store — records `EvidenceChain::verify()` reports as broken. It has been removed. There is one evidence writer, and it signs.

The `AuditChainVerified` observation runs `verify()` and reports the verdict verbatim. It is the only fact in the whole vocabulary proved by cryptography rather than by inspection. `Empty` and `Unanchored` are graded as measurements that could not be made, not as passes: a register with nothing in it and a store that cannot attest its height both leave the control unproven, and grading either as satisfied would be ADR-0041's defect — a control passed by the absence of a measurement.

## SBOM generation

Generate a CycloneDX Software Bill of Materials:

```bash
composer sbom
# or
php scripts/generate_sbom.php sbom.json
```

The SBOM includes all dependencies, versions, licenses, and package URLs in CycloneDX 1.5 format.
