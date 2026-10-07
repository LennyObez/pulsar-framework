# ADR-0059: A chain cannot see its own missing tail

## Status

Accepted. Adds four `#[Api]` types (`EvidenceChainHead`, `EvidenceChainHeadAware`,
`EvidenceChainVerdict`, `EvidenceChainVerification`), replaces
`EvidenceChain::verifyChain(array, ?string)` with `EvidenceChain::verify(int)`,
adds `EvidenceChain::height()`, adds one field (`data.sequence`) to the signed
record layout, and removes the `evidenceStore` parameter from
`ControlEvidenceGatherer`. `FileEvidenceStore` now writes a second file beside
the register.

## Context

The compliance evidence register is what an assessor is handed. Three reviewers
established by execution that its verifier answered `valid: true` for three files
it should have refused, and that two docblocks — one on
`ControlEvidenceGatherer::auditChain()`, one on `TamperEvidentAuditProbe` — told
the reader the opposite in the same words: "truncation, reordering and injection
are all detectable".

The executed results, on a three-record register, before this decision:

| Mutation                      | Store said | Verifier said          |
| ----------------------------- | ---------- | ---------------------- |
| drop the last record          | Healthy    | `valid=YES verified=2` |
| drop two, keep the first      | Healthy    | `valid=YES verified=1` |
| edit `control_id` on the last | Healthy    | `valid=YES verified=2` |
| cut the file mid-record       | Corrupted  | `valid=YES verified=2` |
| delete the register           | Empty      | `valid=YES verified=0` |

None of the three is a bug in a line of code, and fixing them one at a time would
have produced three patches and a fourth hole.

### Truncation is invisible to a hash chain, by construction

Chaining each record to its predecessor's signature proves that no record still
**present** has been altered or moved. Delete the last two records and what
remains is a shorter, perfectly self-consistent chain: every surviving link
verifies against the link before it, because every surviving link is unchanged.
The missing records are missing from the proof as well as from the file.

That is the shape of the primitive, not a defect in a particular verifier. No
amount of re-hashing the survivors recovers it. Only a commitment to the chain's
**height**, made somewhere the register's own bytes are not, can see it.

### A record the reader never looks at cannot be reported by the reader

`EvidenceChain::tail()` and `ControlEvidenceGatherer::auditChain()` both selected
records with `EvidenceStoreInterface::forControl(EvidenceChain::CONTROL_ID)`. The
signature covers `control_id` — so editing it should have been detected. It was
not, because editing it removed the record from the lookup that would have
detected it. The field was inside the signature and inside the selector at the
same time, and the selector ran first.

Any field a reader selects on is a field an attacker edits to become invisible.
The property that matters is not "the signature covers this field" but "nothing
the verifier does depends on a field an attacker can rewrite".

### The report certified a register the store had already refused

`FileEvidenceStore::chainState()` reported `Corrupted` for a file cut
mid-record. `EvidenceChain::resume()` consulted it and correctly refused to
append. `EvidenceChain::verifyChain()` did not consult it at all — it verified
whatever list of records its caller handed in, and the caller built that list from
the lines that happened to decode.

So the same file, at the same instant, was refused by the writer and certified by
the reader. Two answers to one question, which is the failure this codebase keeps
producing.

## Decision

**A register states its own height, under the same key that signs its records,
and the verifier reads the register itself.**

### 1. Every record carries its position, inside the signature

`data.sequence` is a zero-based position, covered by the record's HMAC. A record
cannot be moved, duplicated or renumbered without breaking it, and the verifier
can name **which** positions are absent rather than reporting that something does
not add up.

### 2. The height is attested out of band

`EvidenceChainHead` is written after every append, beside the register rather
than inside it — inside would be removed by the same cut that removes the records
it attests. It carries the chain's genesis commitment, its height, the tail
record's signature, and an HMAC over all of them under the evidence key. A
register two records short of its head has been truncated, and no edit to the
register alone hides that, because forging a head needs the key.

`EvidenceChainHeadAware` is an optional store sub-contract, the way
`EvidenceChainStateAware` is. A store that does not implement it stays usable and
**forfeits the guarantee out loud**: the verdict is `Unanchored`, never `Intact`.
"No truncation was detected by a verifier that cannot detect truncation" is the
sentence this decision exists to stop being printed.

### 3. Membership is established by the signature, not by a lookup

The verifier reads the whole store and treats every record carrying this chain's
marked data bag as a candidate — regardless of `control_id`, and regardless of
whether it authenticates. Editing `control_id`, `id`, `type`, `description` or
`collected_at` now breaks that record's HMAC and is reported as `Modified`.
Gutting the data bag removes the record from the chain's population, which the
anchor then reports as `Truncated`. There is no edit that produces silence.

### 4. Verification consults the store, and the resumer uses the same code

`verify()` takes no record list. It asks the store whether its medium is fully
readable, reads the records itself, and reads the anchor. `resume()` calls the
same analysis, bounded to the tail, so the two cannot disagree about one file.

The bound is on **hashing only**. Whether the medium is readable, whether the
height is attested, which positions are present and in what order — none of that
costs a hash, so all of it is checked over the whole register on every resume.
Only the recomputation of individual signatures is limited, and establishing that
the **last** record is ours is what appending needs: a record altered deep in the
register stays broken forever, and appending on top of it does not bury it.

### 5. Six findings, plus two the register can also be in

A boolean cannot say "two records were removed from the end". `EvidenceChainVerdict`
names what was found, because each case sends an assessor to a different question
and an operator to a different remedy:

`Intact`, `Modified`, `Truncated`, `Reordered`, `Unreadable`, `KeyUnavailable` —
and two more that are not faults in the register but are also not passes:

- `Empty` — nothing is there. A verifier that walks zero records and finds zero
  faults has proved nothing, and `valid` was the wrong word for it.
- `Unanchored` — the store cannot state the height, so completeness was not
  checked. Reported rather than assumed.

Exactly one case is admissible, and `EvidenceChainVerdict::admissible()` is a
method rather than a list at each call site, so a case added later cannot become
admissible by being forgotten.

Inadmissible is not the same as refused. Every case but `Intact`, `Empty` and
`Unanchored` stops the chain appending, because appending onto a register with a
fault in it buries the discontinuity mid-file. `Unanchored` does not: a store that
never offered the completeness guarantee is not a register that has been
interfered with, and taking a deployment's evidence collection offline over it
would trade a stated weakness for a silent gap. The report says `Unanchored` on
every run instead.

### 6. The record is written before the anchor

A crash between the two leaves a register one record **taller** than its anchor.
That is reported `Intact` with a lagging anchor and healed on the next resume,
because only the key holder could have produced that record. The other order
would leave an anchor taller than its register — indistinguishable from a
truncation — and would turn every crash into a tamper alert.

## Consequences

### What the verifier now reports

Executed against the same three-record register, after this decision:

| Mutation                                            | Verdict          |
| --------------------------------------------------- | ---------------- |
| untouched                                           | `Intact`         |
| drop the last record                                | `Truncated`      |
| drop two, keep the first                            | `Truncated`      |
| drop a record from the middle                       | `Truncated`      |
| swap two records                                    | `Reordered`      |
| duplicate a record                                  | `Reordered`      |
| edit `id` / `type` / `description` / `collected_at` | `Modified`       |
| edit any `data` field                               | `Modified`       |
| edit `control_id`                                   | `Modified`       |
| cut the file mid-record                             | `Unreadable`     |
| delete the anchor                                   | `Unreadable`     |
| rewrite the anchor's height                         | `Unreadable`     |
| delete the register, keep the anchor                | `Truncated`      |
| delete both                                         | `Empty`          |
| read under another key                              | `KeyUnavailable` |
| register one record ahead of anchor                 | `Intact`         |

Every case but the last two refuses the next append.

### What it still does not prove, stated rather than discovered

The anchor sits on the same host as the register. Someone who can replace **both**
with an older, genuine pair rolls the chain back to that pair, and nothing inside
the process distinguishes that from a chain that has not run since. Defeating a
rollback needs an anchor the host cannot rewrite — a write-once medium, an
external notary, or shipping each head off-box as it is written. That is an
operator control, and this decision names it rather than claiming it.

### Operational

The register is now two files. `FileEvidenceStore::headPath()` names the second.
Archive them together, back them up together: a register restored without its
anchor reports `Unreadable`, and an anchor restored without its register reports
`Truncated`. `docs/compliance.md` says so where operators read it.

### Not applied to the audit log, and why

`Pulsar\Security\Audit` has the same defect and one more. Executed against a
three-entry audit log: dropping the last entry, dropping two, and dropping the
**first** all report `valid=YES` — the first because
`AuditChainVerifier::verifyChain()` skips the linkage check on its first entry
when no seed is passed, which is what every caller in the tree does. Cutting the
file mid-record gives `AuditFileSink::chainState() === Corrupted` while the
verifier reports `valid=YES`, exactly as the evidence register did.

The same fix does not apply cleanly, and applying a different one would leave the
codebase with two answers again:

- A position field would have to go inside `AuditEntry`'s signed message, and
  that message layout has shipped since 1.0.0. Every entry ever written stops
  verifying. The evidence register could change its layout precisely because no
  release has ever written a record in any layout; the audit log carries a
  `kid`-less legacy path for entries from older releases, which is the opposite
  situation.
- There is no read-back contract to hang an anchor on. `AuditSinkInterface` is
  write-only and `ChainableAuditSinkInterface` promises one HMAC string; nothing
  in the framework reads audit entries back, so `AuditChainVerifier` is fed by
  callers with their own readers.

Making the audit log truncation-evident is a separate decision with a migration
in it. It is recorded here so that it is a known open item rather than a
difference someone discovers by reading one file and not the other.
