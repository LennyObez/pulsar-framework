# ADR-0066: Personal data is measured by classifying something

## Status

Accepted. Adds one `#[Api(since: '1.0.0-rc.12')]` observer to the Compliance module
(`PersonalDataSealObserver`) and one case to the `ObservationId` vocabulary
(`PersonalDataFieldSealed`), and adds that case to the required set of two
existing probes. Everything is additive, which is what
[ADR-0001](0001-ci-gates-and-adr-discipline.md) asks for during the RC phase.
Continues [ADR-0041](0041-the-token-vault-takes-a-connection.md),
[ADR-0061](0061-a-loaded-extension-is-not-a-measurement.md),
[ADR-0062](0062-proof-must-be-about-the-control-subject.md) and
[ADR-0064](0064-a-session-cipher-is-measured-by-sealing-something.md), and pays
the debt ADR-0064 recorded as its own item 6.

## Context

ADR-0061 demoted `extension_loaded('sodium')` from Measured to Available and
ADR-0062 then required a fact to be about the control's own estate. Together they
left GDPR Art 5(1)(f) (Integrity and Confidentiality) and Art 32 (Security of
Processing) resting on nothing at all, and `composer compliance:check` failing on
the shipped default — GDPR is the one framework `config/compliance.php` enables.

Both articles are declared over `ControlSubject::PersonalData`. The evidence set
held two measurements that could have been mistaken for answers about that estate
and are not:

- `key_derivation_verified` runs the KDF against the key in service. Its estate is
  `KeyHierarchy`.
- `session_payloads_sealed` (ADR-0064) seals, opens, and refuses a modified and a
  transplanted session payload. Its estate is `SessionPayloads`, deliberately, and
  ADR-0064 item 6 recorded the consequence in as many words: those two GDPR
  articles, CCPA 1798.150, NIST CSF PR.DS and HIPAA's two encryption controls
  "remain unsatisfiable on every deployment shape this release can build… The
  remedy is an observer for each of _those_ estates."

`config/compliance.php` named what would close them: "an observer that puts a
personal-data payload through the bound cipher suite and reads it back". That
sentence contains the trap this ADR had to get past.

### The obvious repair is the wrong one

Hand a payload to the bound `EncryptorInterface`, read it back, call the fact
`personal_data_sealed`. It would pass every gate in the tree and it would be
false. What such a measurement interrogates is the default encryption subkey — an
estate the vocabulary already has a name for, `KeyHierarchy`, where
`key_derivation_verified` sits. Nothing makes it a fact about personal data except
the shape of the bytes handed in, and **naming an estate by its input is
`extension_loaded('sodium')` with a longer name**: the reading is identical on a
deployment that classifies its personal data and on one that classifies nothing.

The harder truth underneath it: Pulsar does not own the store an application keeps
its personal data in, and never will. No observer in this tree can watch that data
being written. If the estate had to be the application's records, the honest answer
would be to leave both articles red for ever.

### What the framework does own

It owns the RULE that decides when a value is sealed at rest.
`Pulsar\Workflow\Storage\ClassifiedContext` tags each field with a
`ClassificationLevel`, `requiresEncryption()` returns true for `Restricted` and
`Pii` and false for the rest, `serialize()` seals exactly those through the
encryptor the composition root bound, and `DatabaseWorkflowStorage` writes the
result and reads it back through the same rule. That rule is the framework's own
statement about personal data, and it is exercisable.

So the measurement is not "encrypt something and see if it comes back". It is:
**tell this deployment a value is personal data, and observe what it stores.** The
classification is the input; the deployment's response is the fact.

## Decision

**1. `PersonalDataSealObserver` runs four subjects against the deployment's own
at-rest rule.** A synthetic value is classified `ClassificationLevel::Pii` and put
through `ClassifiedContext::serialize()` with the bound encryptor.

1. **the classification is honoured and the stored form conceals the value** — the
   field must be recorded as encrypted _and_ the bytes must not carry the value,
   as they stand or once base64-decoded
2. **the value comes back byte for byte** through `fromSerialized()`
3. **one modified byte is refused**
4. **the same value sealed twice does not produce the same stored form**

Subject 1 needs both halves in one subject, because either alone is met by
something that protects nothing: a deployment that recorded the field as encrypted
and stored it verbatim passes a bookkeeping check, and one that stored something
opaque without consulting the classification passes a concealment check and seals
nothing the next time the rule is asked. The decoded check catches a "cipher" that
base64-encodes the plaintext.

Subject 4 is the one neither word in the article's title names, and it is not
theoretical: a deterministic seal passes 1, 2 and 3 and still hands whoever holds
the storage an equality oracle over the whole table — which data subjects share a
postcode, a diagnosis, an employer — without a single record being opened.

**2. Subject 3 asks the cipher directly; the others go through the read path.**
This asymmetry is deliberate and it is the sharpest thing in the file.
`fromSerialized()` json_decodes what the cipher returns, so an unauthenticated
stream cipher — where one modified ciphertext byte flips one plaintext byte —
fails to produce valid JSON _most_ of the time and is refused for the wrong reason.
Reporting that as "the personal data at rest is authenticated" would be certifying
a coin flip: the same cipher hands the row over whenever the flipped byte lands
somewhere JSON tolerates. So the integrity question is put where it can only have
one answer, and the docblock says why.

**3. The measurement writes nothing.** `serialize()` _returns_ the at-rest form, so
the bytes `DatabaseWorkflowStorage` would put in the row are inspectable without a
row existing. No workflow is started, no table is touched, and there is no cleanup
that can fail. This is the `SessionSealObserver` shape, not the `TokenVaultObserver`
one.

**4. Absence is reported absent, never subjectless.** A deployment with no
encryptor has not escaped the question — it processes personal data and writes it
exactly as the application left it. `Observation::noSubject()` would take the
control out of the coverage denominator, which is how "there is no transport to
encrypt" once satisfied an encryption control (ADR-0062, defect A5). So the run is
reported as one that could not happen, which observes absent, and the control
fails.

**5. The estate is `PersonalData` and nothing wider.** Not
`ConfidentialInformation`, though `Restricted` takes the same branch in
`requiresEncryption()` — a fact minted from a `Pii` field cannot answer for the
contracts, pricing and source code that estate holds. Not `HealthData` or
`CardholderData`, which are siblings that do not nest (ADR-0062). Consequence
recorded below.

**6. Two probes gain the fact as REQUIRED, per-item.**

- `DataProtectionAtRestProbe` — its own docblock said these controls stay
  unsatisfiable "until an observer exists that puts THOSE estates through their own
  encryption path". This is that observer. GDPR Art 5(1)(f) and CCPA 1798.150 are
  declared over `PersonalData` and now have a fact that can carry them.
- `CryptographicControlProbe` — GDPR Art 32(1)(a) names "the pseudonymisation and
  encryption of personal data", and this is the encryption half, measured. The
  pseudonymisation half is measured too (ADR-0065) and is deliberately **not**
  required here: Art 32 says "as appropriate", so a deployment that encrypts its
  personal data and pseudonymises nothing has not failed Article 32, and requiring
  both would invent an obligation the article does not impose.

`HealthDataProtectionProbe` was deliberately **not** touched. Adding a
personal-data fact to a health-data control would put a requirement in front of an
operator that could never decide the control and could only fail it for the wrong
reason.

**7. The encryptor is resolved by contract, not by concrete class.**
`EncryptorInterface` is `#[Api]` and `SecurityWiring` binds it by contract, so an
application can answer it with a legacy cipher, an HSM shim or a wrapper carrying a
key id — and none of those is necessarily authenticated or randomised. Resolving by
contract is what makes those deployments assessable rather than assumed sound.

## What moved

Recorded by running the assessment, before and after, against the real composition
root:

| Deployment            | Before                                                    | After                                |
| --------------------- | --------------------------------------------------------- | ------------------------------------ |
| `withNothing`         | operator_responsibility 115, unsatisfied 100              | unchanged                            |
| `fullyEquipped`       | operator_responsibility 115, satisfied 16, unsatisfied 84 | satisfied **19**, unsatisfied **81** |
| `fullyEquipped`, gdpr | 2 satisfied                                               | **4 satisfied**                      |
| `fullyEquipped`, ccpa | 0 satisfied                                               | **1 satisfied**                      |

**Exactly three controls moved, and all three are declared over `PersonalData`:**
GDPR Art 5(1)(f), GDPR Art 32 and CCPA 1798.150. Nothing moved on a deployment that
has nothing, which is correct — a deployment with no encryptor seals nothing.

`composer compliance:check` passes on the shipped default for the first time since
ADR-0061, and it passes because the estate was measured rather than because a
mapping was weakened, a status was edited or GDPR was removed from
`enabled_frameworks`. Unset `PULSAR_MASTER_KEY` and three of GDPR's four probed
controls go red again.

NIS2 Art 21(h) and NIST CSF PR.DS cite the two probes that gained the fact and did
**not** move: they are declared over `CryptographicPlatform` and
`ConfidentialInformation`, the estate join holds the fact aside, and each finding
now names it as exercised elsewhere. `PersonalDataSealObserverTest` asserts that
directly, so widening the fact to make them green would fail a test rather than
pass a review.

## What this does not fix

Carried forward from ADR-0064, with what this repair touched marked:

1. **The grade-blind "all required present" test in `reach()` survives.** TOUCHED
   in the same direction ADR-0064 touched it: both probes here still hold Available
   and Declared facts in required slots, and those facts still prove nothing. They
   are kept because they are the prerequisites — no libsodium, no key, no cipher —
   and because a report that stopped printing them would tell an assessor less.
2. `MeasuringComponent` still seals who may produce a fact, not what they may say.
3. "Claimed and not observed" is still spelled `ControlOutcome::Unsatisfied`.
4. **Estate granularity.** TOUCHED, and chosen narrow again.
5. **Controls that can never be Satisfied.** ADR-0064 listed five: GDPR Art
   5(1)(f), CCPA 1798.150, NIST CSF PR.DS and HIPAA's two encryption controls. TWO
   of those five left the list here — Art 5(1)(f) and 1798.150, the ones declared
   over `PersonalData` — and GDPR Art 32 left with them, which was on ADR-0061's
   longer list of nine rather than on ADR-0064's five. So three controls moved and
   three of ADR-0064's five remain: NIST CSF PR.DS and HIPAA's two. NIS2 Art 21(h)
   is stuck alongside them for a different reason — its estate is
   `CryptographicPlatform`, whose only fact is a loaded extension, so nothing short
   of a new fact about the bound suite could reach it. The three estates that need
   the same work are `ConfidentialInformation`, `HealthData` and
   `CryptographicPlatform`, and none of them is closed by renaming this fact.
6. **The application's own classification is unobserved, and always will be.** This
   ADR establishes that when the deployment is told a value is personal data, it
   seals it, gives it back, refuses a modified copy and does not repeat itself. It
   does not establish that the application classifies its personal data correctly
   or routes it through this path at all. That residual is the same one PCI Req 3.4
   carries for the token vault and Art 25 carries for pseudonymisation; it is
   outside what any framework can observe, and `config/compliance.php` now says so
   where an operator reading a green report will see it.

**What was refused outright:** naming the fact after the bytes handed in. An
observer that put an email-shaped string through `EncryptorInterface` and called
the result a personal-data measurement would have closed the same two controls with
one file and no new concepts. It was refused because the resulting fact would read
identically on a deployment that classifies nothing, which is the defect ADR-0061
and ADR-0062 exist to remove — and closing a gate with it would have been those two
ADRs being spent rather than honoured.
