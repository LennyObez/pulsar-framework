# ADR-0061: A loaded extension is not a measurement

## Status

Accepted. Adds one `#[Api]` enum case (`ObservationGrade::Available`), one `#[Api]` value
(`PlatformCapability`, with `offered()`, `absent()` and `notInspected()`), one `#[Api]`
factory (`Observation::available()`) and one exception factory
(`UnmeasuredSubjectException::nothingNamed()`). Purely additive: no signature changes, no
removals. Four facts are regraded and `ControlEvidenceGatherer::fromRuntimeCheck()` is
split into three adapters. On a fully-equipped deployment the satisfied count falls from
32 to 25.

Amends [ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md) §4,
which said only `Measured` proves behaviour. That is still true. What changes is that the
set of grades which do not prove behaviour has a third member, and the reason it needed
one.

## Context

`ObservationGrade::provesBehaviour()` was narrowed to `Measured` alone by ADR-0050, and
the design assumed the remaining question was who may produce a fact. An adversarial
audit found that the harder question was untouched: what a check has to DO before its
result may be called a measurement.

`ControlEvidenceGatherer` had one adapter, `fromRuntimeCheck()`, and every result from
`RuntimeVerifier` went through it into `Observation::measured()`. The reasoning was that a
check which executes is a measurement. Three checks went through that method, and they are
three different kinds of fact:

| Check                        | What it actually does                                                                           |
| ---------------------------- | ----------------------------------------------------------------------------------------------- |
| `runtime.sodium_extension`   | `extension_loaded('sodium')` and `function_exists('sodium_crypto_generichash')`                 |
| `runtime.fips_mode`          | reads the bound `CipherSuiteInterface` against an approved list, plus OpenSSL introspection     |
| `runtime.master_key_derived` | runs `sodium_crypto_kdf_derive_from_key` against the key in service and checks three properties |

Only the third could answer differently on two deployments that differ in the thing the
control is about. The first answers out of the PHP build: identically on a deployment that
encrypts every field and on one that encrypts nothing.

At grade `Measured`, `runtime.sodium_extension` was admissible proof, and it was the sole
admissible proof under **nine controls across seven frameworks** — CCPA 1798.150, GDPR
Art 5(1)(f) and Art 32, HIPAA 164.312(a)(2)(iv) and its 2026 twin, ISO 27001 A.8.24, NIS2
Art 21(h), NIST CSF PR.DS, PCI DSS Req 3.4. GDPR Art 5(1)(f) reached Satisfied on a loaded
PHP extension, in the shipped default.

A fourth fact had the same defect from the other direction. `master_key_material` comes
from the security-posture preflight, which reads `PULSAR_MASTER_KEY` out of the process
environment, confirms the string parses into a key, and reads two booleans saying whether
the wiring bound a `MasterKey` and an encryptor. It was graded `Measured` on the argument
that the environment is "a property of the running process". Where a configured value is
read from does not change what reading it establishes, and binding presence is the claim
[ADR-0045](0045-a-control-status-is-observed-not-written.md) §3 says the vocabulary
deliberately has no case for.

## Decision

### 1. A fact is graded by what its check could distinguish, not by where it is published

The test applied per call site: could this fact come back differently on two deployments
that differ in the thing the control is about? A fact whose answer cannot differ between
those two deployments is not evidence about either of them.

### 2. `ObservationGrade::Available` — the platform offers it and nothing here used it

None of the existing three grades was honest for "ext-sodium is loaded". `Resolved` names
the class that will serve requests, and no class was named — a deployment can carry
libsodium and bind no cipher suite at all. `Declared` records a configuration value, and
nothing was configured. `Asserted` records an operator claim, and no operator claimed
anything. Grading it as any of the three would have printed a false badge beside it.

Review argued against a fifth case: no code branches on it, because the decision table
reads only `provesBehaviour()`, so `Available` and `Resolved` are interchangeable to every
consumer in the tree. That is correct, and the case is not defended on the table. It is
defended on the reader, who is an assessor: the report prints the grade beside every fact,
and `cryptographic_capability (resolved)` would tell that reader a class was resolved when
none was — the same species of false badge as the `(measured)` this change removes.

The weak half, recorded rather than argued away: exactly **one** fact reaches this grade
today. The case rests on the vocabulary having no honest slot for "the platform offers
it", not on breadth of use.

`provesBehaviour()` is unchanged. `Measured` alone.

### 3. The new grade is sealed like the old one

`Available` is reachable only from `PlatformCapability`, whose three named constructors all
refuse a caller outside `src/Compliance/Evidence/` (see
[ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md)). Its
constructor additionally refuses "the platform offers it, and nothing was named", the way
`Measurement` refuses "measured, and nothing was measured" — a capability that names no
primitive prints in a report exactly like a platform that was inspected.

`absent()` and `notInspected()` are kept apart although both observe absent, for the reason
`Measurement::couldNotRun()` is kept apart from a failed run: only one of the two justifies
telling an operator to change their build, and an operator sent to install an extension
that is already installed will change nothing and see the same finding again.

### 4. FIPS validation is graded `Resolved`, not `Available`

The brief that ordered this work said to grade `runtime.fips_mode` `Available` beside the
sodium check. Review said FIPS 140 validation is a property of a module build rather than
an exercisable behaviour, and that resolution of the bound suite is its honest ceiling.
Review is right, and the deciding argument is the one that justifies `Available` existing
at all — what the word tells the reader.

What decides the FIPS check is which `CipherSuiteInterface` this deployment bound and
whether that suite is on an approved list. That is a resolution, and it is the only input
that can produce a pass; the platform introspection beside it can only ever take a pass
away. A present observation here means the deployment bound an approved suite and the
module running it is validated. Printing `(available)` beside that would say FIPS was on
offer and unused, when in fact it is in use.

### 5. The split is per call site, because a blanket regrade would destroy a real measurement

`runtime.master_key_derived` keeps `Measured`, and earns it: it takes the key in service,
runs the KDF, and asserts the requested length, reproducibility for the same subkey id and
context, and different material for a different context — the domain separation
[ADR-0006](0006-libsodium-only-crypto-master-key-derivation.md) has every subsystem relying on. It is the
only key-management property in the evidence set established by running something.

`fromRuntimeCheck()` is therefore three adapters — `platformCapability()`,
`boundModuleResolution()` and `runtimeMeasurement()` — over one shared reading of the
verifier's result, and each names which of the three kinds of thing its check touched.

## Consequences

### The numbers, and which controls moved

A fully-equipped deployment satisfied 32 controls before this decision and satisfies 25
after. Seven of the nine controls that rested on a loaded extension moved to `Unsatisfied`
and now read "Claimed and not observed", naming `cryptographic_capability (available)`:
CCPA 1798.150, GDPR Art 5(1)(f), GDPR Art 32, HIPAA 164.312(a)(2)(iv) and its 2026 twin,
NIS2 Art 21(h), NIST CSF PR.DS.

Two of the nine kept their Satisfied, and which two is the evidence that this demoted a
FACT rather than a set of controls: ISO 27001 A.8.24 on the KDF running against the key in
service, and PCI DSS Req 3.4 on the token vault rendering a value unreadable.

Regrading `fips_validated_cryptography` and `master_key_material` moved **no** control in
either direction. Both are supporting facts in every mapping that names them and required
in none. What changed is the evidence column: an assessor stops reading "(measured)" under
seven controls that measured no cryptography.

`withNothing` is unchanged, at 0 satisfied.

### Two controls can now never be Satisfied, and that is stated rather than discovered

`CryptographicControlProbe` requires the capability fact and a resolved `MasterKey`;
`DataProtectionAtRestProbe` requires it and a Declared session cipher. Neither probe now
requires anything that can reach `Measured` on any deployment, so five controls — GDPR
Art 32, NIS2 Art 21(h), CCPA 1798.150, GDPR Art 5(1)(f), NIST CSF PR.DS — cannot pass
until something exercises the cryptography rather than confirming it is installed.

A control that can never pass is the same class of broken instrument as one that can never
fail, which is the defect this decision removed. The trade is made openly and is not
closed here: the remedy is an observer that puts a payload through the bound cipher suite
and reads it back, as `TokenVaultObserver` does for the token vault. It is named in
`ControlEvidenceGatherer::cryptographicCapability()` and in the affected tests. Adjusting a
grade to make those controls green again would be the defect wearing this ADR's approval.

Three frameworks satisfied nothing at all on an equipped deployment before this change;
five do now. GDPR, CCPA and NIS2 joined eIDAS and the AI Act for a reason worth stating
plainly: the controls they declare here are cryptographic, and this release measures no
cryptography except key derivation and the token vault.

### `composer compliance:check` fails with two more entries

The gate assesses the framework's own default deployment and fails on any control an
enabled framework claims and the deployment does not show. It already failed with two
(GDPR Art 25 and Art 33). It now fails with four: GDPR Art 32 and Art 5(1)(f) join them,
which is precisely the defect this ADR exists to report rather than a regression the ADR
introduces. Closing it needs the observer above, or GDPR removed from
`enabled_frameworks` — and the second is a claim about the product, not a fix.

### What this decision does not fix

- `ProbeVerdict::reach()` still asks whether every required fact is PRESENT before it asks
  whether any of them proves anything. An `Available` observation therefore still fills a
  required slot, so a control whose only unmet requirement is a platform primitive reads as
  claimed-and-not-observed rather than as partial. Deferred to its own decision.
- "Claimed and not observed" is still spelled `ControlOutcome::Unsatisfied`, so a control
  nobody measured is counted in the same column as one that was measured and failed.
- Nothing here constrains WHAT a measuring component may say a fact is about; the seal
  constrains only who may say it.
