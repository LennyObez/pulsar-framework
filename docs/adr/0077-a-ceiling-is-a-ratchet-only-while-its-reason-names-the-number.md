# ADR-0077: A ceiling is a ratchet only while its reason names the number, and a by-reference argument is a write

## Status

Accepted. Changes no runtime code and no `#[Api]` surface: one CI analyser, one test-support
class, two test classes, the ceiling file and the regenerated class-shape baseline.

Continues [ADR-0068](0068-a-baseline-records-what-was-measured.md), which set the ceiling this
record raises, and applies
[ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md) to the
ratchet ADR-0068 relied on.

## Context

Two defects, both found while measuring for a third. Neither was in any register.

### The ratchet did not ratchet

`tools/php/analysis-baseline-ceiling.json` exists so that a suppression count cannot grow
quietly. [`.github/GOVERNANCE.md`](../../.github/GOVERNANCE.md) says so in as many words:
counts "may only shrink. Raising a ceiling is permitted — but it must be a deliberate line in a
diff that says so, never a silent side effect of regenerating a baseline."

Measured on 2026-09-02 against the file as it stood: change `findings` from 1286 to 1295 —
nine findings buried — leave `why` and `raisedFrom` exactly as they were, and run the ratchet.

```
$ php vendor/bin/phpunit -c tools/php/phpunit.xml tests/Unit/Tooling/AnalysisBaselineRatchetTest.php
OK (7 tests, 126 assertions)
```

Exit 0. The only comparison anything made was the measured count against the recorded one, and
the recorded one is whatever the last editor typed. Two briefs in this session and one
governance page named this file as the thing that forced a deliberate, reviewable moment before
a suppression count could grow. It forced nothing.

### The mutability analyser could not see a write through a by-reference argument

`FileVisitor` decided whether a call wrote through its argument by consulting a hand-written
lists holding thirty-two global functions between them — `sort`, `array_push`, `preg_match` and their neighbours —
under a comment stating that "there is no way to know the by-reference signature of an
arbitrary callee from the AST, so this covers the mutators that actually appear in PHP code and
the limitation is reported rather than hidden."

Both halves of that sentence were wrong. `ReflectionFunction` reports exactly which parameters
an internal function takes by reference, and the index this tool already builds holds the same
fact for every first-party callee. Nothing was reported anywhere.

What the list missed, in this repository, today:

| Property                               | Written by                                                        | The gate's verdict   |
| -------------------------------------- | ----------------------------------------------------------------- | -------------------- |
| `PseudonymizationService::$derivedKey` | `sodium_memzero($this->derivedKey)` when the service is destroyed | "make it `readonly`" |
| `SealedArchiveWriter::$state`          | `sodium_crypto_secretstream_..._push($this->state, …)` per chunk  | "make it `readonly`" |

PHP rejects a `readonly` property as a by-reference argument at runtime. The gate was not
merely silent about two mutable properties: it was recommending, in the crypto path, a change
that turns the next backup and the next pseudonymisation into an `Error`.

## Decision drivers

1. A control never observed to refuse anything is indistinguishable from no control, and this
   one was being cited as the reason suppression growth was safe.
2. An analyser that reports on less than it claims makes its own silence unreadable. "Written
   only during construction and never again" was a measurement in most places and a guess in the
   places libsodium touches.
3. A number recorded without its reason moving with it is a number nobody has to defend.

## Decision

### 1. Every ceiling names its own number, and the reason has to move with it

`tests/Unit/Tooling/Support/BaselineCeilingRecord.php` reads the ceiling file and refuses a
number the file does not account for. Each entry must carry `raisedFrom` (the ceiling this one
replaced, or `null` the first time the figure was written down), `raisedOn`, and a `why` that
**contains the current `findings` value as a standalone integer** — and the previous one when
the entry records a raise.

That single citation rule is what binds the number to the prose. Change `findings` alone and
the reason no longer names it. Change `findings` and `raisedFrom` together and the reason still
does not name the new figure. The only way back to green is to write down what the new number
is and why it is the new number, in the same diff.

The rule is file-local on purpose. Comparing against `git show HEAD:` was the obvious
alternative and it fails exactly where this has to work: a shallow CI clone, a worktree, a tree
whose ceiling file is not yet committed. A rule that silently skips in those conditions is the
defect this record is about.

It cannot tell a rewritten reason from a good one. Nothing mechanical can. It can make the
rewrite happen, in front of a reviewer.

Four negative tests plant the defect and watch the refusal: a raise with the reason left alone,
a raise recorded as a raise from a number to itself, a ceiling with no raise record at all, and
a ceilings map that is empty. A fifth plants a raise whose reason names both figures and
watches it pass, because a rule that refuses every raise is not a ratchet either and an exit
code cannot tell the two apart.

### 2. By-reference signatures are asked for, never listed

`globalFunctionSignature()` asks `ReflectionFunction` for the by-reference positions of any
global function the analysing process can load. `Index::resolveByReferenceCalls()` answers the
same question for a method or a first-party function, from the declarations the index already
holds — every `&$param` in the tree, inherited through parents, traits and interfaces — and
falls back to `ReflectionMethod` for a vendor or core class. Named arguments are mapped to
positions through the callee's own parameter names; a by-reference variadic takes every
argument from its position onward.

Three answers, in order of what they are worth:

1. The callee resolves. The signature is a fact and the write is kept or dropped on it.
2. The receiver could not be typed, but every declaration of that method name in the tree
   agrees about the position. The answer is the same whichever class the receiver is.
3. Declarations disagree, or the callee is nowhere. Nothing is credited, and the call is
   **reported** as undecidable — the sentence the old comment claimed and never did.

Two boundaries were found by measuring rather than by reasoning:

- **An array literal is not a destructuring target.** `extract(['page' => $result->page])`
  passes a literal to a by-reference parameter. Walking into it the way an assignment's
  left-hand side is walked credits a write to `$page` on an immutable record, which turned
  `ListResourceResult` from a value object into a collaborator and produced two dependency
  findings that were not defects. Array and list literals are now values wherever they appear
  as arguments.
- **A by-value parameter must stay by-value.** The fixture that proves the fix is paired with
  one that proves it discriminates: the same call shape with `array $rows` instead of
  `array &$rows` is still reported. Without that pair, "every argument is a write" would pass
  every test above while silencing the immutability question wholesale.

The visible cost of answer 3 is that the gate's `Undecided` section grew from 25 entries to 72.
The 47 additions are calls that hand a property to a callee this process cannot resolve, and
they are dominated by optional extensions — `apcu_*`, `shmop_*`, `Imagick`, `AMQPConnection`,
`Memcached`, `Grpc\ServerCredentials` — none of which is in the framework's `require`. That is
the honest consequence of asking the engine: the analysis is as complete as the runtime it runs
in, and where it is not, it says which call it could not answer instead of counting it as
harmless. A run on a machine carrying those extensions resolves them and the section shrinks.

### 3. What the change to the analyser costs, measured

Both scripts run over the same working tree, at the same moment, with the same `--index` roots
— the unfixed one from the worktree at the 25 August 2026 branch tip, the fixed one from the tree this commit
ships:

| Blocking findings | Unfixed gate | Fixed gate |
| ----------------- | ------------ | ---------- |
| total             | 1294         | **1292**   |
| appeared          | —            | 0          |
| disappeared       | —            | 2          |

The two are the rows in the table above. Nothing else moved. An earlier measurement of the same
pair, before the array-literal boundary was found, showed two disappearing and two appearing;
the two that appeared are the `ListResourceResult` sites, and they are why the boundary exists.

### 4. Seven findings are recorded, each for its own reason

The regenerated baseline holds 1292 entries. Thirty-two of the differences from the previous
1286 are addresses that moved when a file grew, and thirty-three are addresses that no longer
produce a finding at all. Seven are findings recorded here for the first time:

| Finding                                                                                                     | Why it is recorded                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ----------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ControlEvidenceGatherer` → `AiGovernanceRecordObserver`, `AiMonitoringObserver`, `BackupRoundTripObserver` | The seal of [ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md), for the third, fourth and fifth time. `MeasuringComponent` admits an `Observation` only from a class declared inside `src/Compliance/Evidence/`, so an injectable seam here is a hole in that seal. Four siblings on the same constructor — `DatabaseTlsObserver`, `AiTransparencyObserver`, `DataPathVerifier`, `EvidenceChain` — are already recorded, and the constructor argues each one in its own comment.                                                                                         |
| `RoutingConnection::prepare(): Statement`, `::beginTransaction(): Transaction`                              | `#[Override]` implementations of `ConnectionInterface::prepare()` and `::beginTransaction()`, whose own declarations are recorded at `ConnectionInterface.php:37` and `:42`. Nineteen entries of this exact shape are already accepted, across nine other implementations; clearing these two means changing the return types of an `#[Api]` interface at all ten. That is a real BC break during RC to satisfy a lint that accepts the shape nineteen times.                                                                                                                                    |
| `SealedArchiveBackupService` → `ArchiveSeal`                                                                | The one with no precedent, and it needed its own answer. `ArchiveSeal` is the declared owner of `SubKeyId::BackupArchiveSeal` and the only file in the tree that derives the archive key. The seam a consumer actually needs is one level inside it — `KeyProviderInterface`, which is exactly where an HSM or a KMS is substituted — and the service itself is already reachable through `BackupServiceInterface`. An interface at this point would let a substitute decide how the archive key is derived while the archive still looks sealed, which is the property the seal exists to hold. |
| `SealedArchiveWriter::$handle`                                                                              | A promoted constructor parameter holding an open stream. The verdict is "make it `readonly`" and its own blocker is "readonly requires a declared type": PHP has no type for a resource, so the strongest form the gate can name is unreachable by declaration. `private readonly mixed $handle` would satisfy it at the cost of replacing `@param resource` with the widest type in the language, and that trade belongs to the review of the backup subsystem rather than to a commit about a gate.                                                                                            |

### 5. The ceiling becomes 1292

`tools/php/analysis-baseline-ceiling.json` records 1292, raised from 1286, with the reason
above written into the entry — which is now the only way the number is allowed to be there.
The PHPStan entry gains the same record: 10, never raised, five blocks of tautological
assertions the baseline's own header enumerates.

## Alternatives considered

### Compare the ceiling against the committed version of itself

Rejected. It is the strongest possible binding and it is unavailable exactly where the gate
runs: a shallow clone has no previous version, a worktree may have a different one, and a
ceiling file created in the same commit has none at all. A check that skips when it cannot
answer teaches everyone that it passes.

### Add the libsodium functions to the list

Rejected. The list was the defect, and this is the same defect one iteration later: the next
extension's mutators are missing again, and nothing says so. The engine already knows.

### Fix the seven rather than record them

Rejected per item, in the table above. Two would change an `#[Api]` interface across ten
classes; four are seals where the interface is the regression; one is unreachable in PHP's type
system.

### Teach the gate that an observer is a seal

Rejected, and for the same reason ADR-0068 rejected it: changing an analyser's rules inside the
commit that makes the analyser green is the one move a reviewer cannot check cheaply. The
by-reference change in this record is the opposite case — it makes the analyser report more
truthfully, and it is proved by fixtures that fail against the previous version.

## Consequences

### Positive

- Raising a suppression ceiling now costs a rewritten paragraph that names the new number, and
  a reviewer sees both halves in one diff.
- Two properties in the crypto path stop being told to become `readonly`, which they cannot be.
- Every `&$param` in the tree is now part of the immutability question, including the ones
  nobody has written yet.
- A callee whose signature cannot be resolved is reported instead of assumed harmless.

### Negative

- The recorded ceiling is six larger than the one it replaces. Thirty-two of the entries behind
  that difference are the same findings at moved addresses; seven are new and are argued above;
  the arithmetic only balances because thirty-three stale entries were dropped in the same
  regeneration.
- A run whose PHP is missing an optional extension cannot resolve that extension's functions,
  so two machines can produce different `Undecided` sections. The verdicts they disagree about
  are named on both, which is the difference between a limit and a blind spot.
- `SealedArchiveWriter::$handle` is recorded rather than fixed, and the fix that exists is a
  widening of its declared type.

### Neutral

- The gate's own wall clock is unchanged: both full runs over `src/` and every extension
  completed in about five minutes on the same machine. Reflection is memoised per callee name,
  and the deferred pass is linear in the number of calls that hand a property to a callee.

## Security impact

None to the runtime; no shipped code changed. The two findings the analyser stopped producing
are both in cryptographic code, and in both the advice it had been giving would have broken the
path: `sodium_memzero()` cannot take a `readonly` property, so the pseudonymisation key would
have stopped being wiped — or, if the change were made as recommended, the process would have
failed on the write instead. The gate now says what those two properties are: internally
written state that must stay `private(set)`.

## Performance impact

None. Nothing on a request path changed.

## Migration / rollback plan

Nothing to adopt. To roll back, restore the previous baseline and ceiling and revert the
analyser — which restores a gate that reports two findings whose recommended fix is a runtime
`Error`, and a ratchet that accepts any number.

## Links

- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md) — a
  check never observed to fail; this record is two of them
- [ADR-0068](0068-a-baseline-records-what-was-measured.md) — the measurement that set the 1286
  this raises
- [ADR-0050](0050-a-fact-is-produced-only-by-the-component-that-measures.md) — the seal three of
  the seven recorded findings reuse
- `tools/ci/assert-substitutability-and-immutability.php` — the analyser
- `tests/Unit/Tooling/Support/BaselineCeilingRecord.php` — the rule that reads the ceiling file
- `tests/Unit/Tooling/AnalysisBaselineRatchetTest.php` and
  `tests/Unit/Tooling/ClassShapeGateTest.php` — the plants and the refusals
