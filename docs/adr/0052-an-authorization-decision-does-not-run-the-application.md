# ADR-0052: An authorization decision does not run the application

## Status

Accepted, and amended by
[ADR-0057](0057-a-guard-that-is-not-call-scoped-guards-nothing.md). Decision 1
below stands as written. Decisions 2, 3 and 5, and the two consequences about
the destructor and the benchmark, described a design the code did not implement:
the re-entry guard was a property of the Gate rather than of the call stack, the
threshold flush ran inside `Gate::allows()` on every shipped deployment, the
reports were made through a logger that can throw, and the benchmark measured a
buffer threshold no `config/security.php` ever contained. ADR-0057 makes each of
them true. Read the two together.

Supersedes decision 1 of
[ADR-0048](0048-a-guard-is-something-a-request-runs-into.md), which made the Gate
record its decisions by dispatching them through the application's event
dispatcher. The recording stands; the mechanism does not. Replaces one
`#[Internal]` class (`AuthorizationDecisionAuditListener` →
`BufferedAuthorizationDecisionSink`), changes the `Gate` constructor's third
parameter, and adds two `#[Api]` types and one shipped config key.

## Context

ADR-0048 found that `Gate::allows()` recorded nothing, because `AuthWiring`
built the Gate with no event dispatcher and both dispatch paths opened with
`if ($this->eventDispatcher === null) { return; }`. It fixed that by passing the
dispatcher and registering a listener that turned the dispatched envelopes into
HMAC-chained audit entries.

The finding was right. The fix put the application inside the authorization
decision.

### Every listener in the application ran inside every decision

`ListenerProvider` keys listeners by the dispatched object's class, and every
envelope is an `EventEnvelope`, so the Gate's dispatch reached every listener any
part of the application had registered for envelopes — not only the audit one.
`EventDispatcher` catches each listener's exception, logs it, and then re-throws
the first one after the loop:

```php
if ($listenerErrors !== []) {
    throw $listenerErrors[0];
}
```

That throw surfaces from `Gate::allows()`. An unrelated listener with a bad day
turned an allowed request into a `500`, and a slow one made every authorization
check slow. Neither failure has anything to do with authorization, and no
configuration of the dispatcher separates the two: what runs inside a decision
became a property of what else the application had subscribed to.

### Re-entry was real, and it corrupted what it was there to protect

A listener that asks the Gate a question re-enters `allows()` → dispatch → the
same listener. Nothing bounded it except `StormGuard` eventually throwing
`EventException` — which unwinds out of `allows()`, so the outer decision is
never recorded while the nested ones already have been. The audit trail ends up
holding the consequences of a decision it has no record of.

### It cost fourteen times the decision

Measured on one machine (PHP 8.5 ZTS, xdebug off, opcache on, 8 000 revolutions
per round, variants interleaved round-robin, microseconds per decision, median of
three runs):

| Gate                                                 | allow | deny |
| ---------------------------------------------------- | ----- | ---- |
| Before the dispatcher — recording nothing            | 4.0   | 3.9  |
| Dispatcher wired — the shipped defect                | 58    | 58   |
| Dispatcher wired, twelve unrelated listeners as well | 60    | —    |

`AuthorizationBench` did not see it: every Gate subject in that file builds a
Gate with no recording at all, so a fourteen-fold regression on the path a
request crosses once per protected resource passed a benchmark suite that has a
budget for exactly this.

## Decision

**1. The Gate talks to a dedicated sink, never to a dispatcher.**
`Gate::__construct()` takes an `AuthorizationDecisionSinkInterface` where it took
an `EventDispatcherInterface`, and hands it one `AuthorizationDecision` per
decision — identity, permission, resource, outcome, ground, instant. The sink is
one named collaborator the composition root chooses. Nothing else is on the
path, so nothing the application registers can throw inside a decision, slow one
down, or be reached by one.

The `AuthorizationGranted` / `AuthorizationDenied` events keep their `#[Api]`
place and are still what a decision is expressed as; the sink constructs one per
decision and records the envelope's payload hash. They are no longer dispatched
to application listeners. That is a removal of behaviour ADR-0048 introduced two
weeks ago, not of behaviour anything shipped before it: the dispatcher argument
had never been passed, so no released Pulsar ever delivered one of these events
to anyone.

**2. The Gate contains a sink that misbehaves, in two named ways.**

Everything the sink raises is caught and reported at `critical`. A transient
audit-sink fault must not change an authorization outcome; the alternative is the
defect above, with the sink's own failure as the trigger instead of a stranger's.

Re-entry is refused rather than recursed. A decision reached while the Gate is
recording is queued and handed to the sink after the outer record returns, so
there is never a second `record()` frame open. The queue and the drain share a
ceiling of 16, past which further nesting is refused and reported at `critical` —
a sink that produces a decision per record is a defect, and the Gate's job is to
keep serving requests while it is fixed rather than to let it run until something
throws. The nested decisions that fit are still written, and in order.

**3. The chained write happens after the decision has been returned.**
`BufferedAuthorizationDecisionSink::record()` captures the decision and the
`RequestContext` it was made in, and returns. The entries are built and chained
by `flush()`, which `AuthWiring` registers on the kernel's `TerminateEvent` — the
one hook that runs past the end of a request without being inside any decision —
and which the sink also calls itself once `decision_audit_buffer` decisions are
waiting.

Three flush points, because none of them covers everything on its own.
`TerminateEvent` is dispatched only from `Kernel::run()`, so a console command, a
queue worker and a runtime adapter that calls `handle()` directly never reach it.
The threshold covers a loop that never ends but not a short-lived process. The
sink's destructor covers both, and it is the one event every process has: PHP
runs it on normal shutdown, on `exit()`, and after an uncaught exception the
kernel has rendered. `flush()` reports every write failure and raises none, so
running it during shutdown cannot turn a clean exit into a fatal one.

The drain is bounded at eight passes. A flush normally makes one — take the
buffer, write it, find nothing new. A second means writing an entry produced
another decision, which is what happens when an application's audit sink asks the
Gate a question on its way to disk. The ceiling stops that becoming a
`terminate()` that never returns; what is left over stays buffered for the next
flush, so the bound costs latency rather than records.

The context is captured rather than read at write time because
`RequestContextMiddleware` clears it when the pipeline unwinds, and the terminate
flush runs after that. An entry built from ambient state would carry no
correlation id at all. Capturing also makes the id right rather than merely
present: it names the request the decision was made in, not the one that happened
to be open when it was written. `AuditLogger` already lets explicit metadata win
over its own enrichment, so passing them explicitly is all that is required.

**4. The evaluation itself lost the work that pays for the record.**
Three things in `allows()` were doing work no deployment needs. A default
`PolicyContext` was constructed on every call including the majority with no ABAC
policies registered, where it is observable by nothing. The super-role check
walked the identity's roles comparing each against a list that is empty by
default. Both permission scans used `array_any` with a closure where a `foreach`
says the same thing in a third of the time.

None of that is a consequence of this ADR, and it is recorded here because it is
what makes the number below true rather than nearly true:

| Gate                                       | allow | deny |
| ------------------------------------------ | ----- | ---- |
| Before the dispatcher — recording nothing  | 4.0   | 3.9  |
| Dispatcher wired — the shipped defect      | 58    | 58   |
| Dedicated sink, deferred write — this ADR  | 4.3   | 4.6  |
| Dedicated sink, `decision_audit_buffer: 1` | 60    | —    |
| Evaluation alone, no sink bound            | 2.1   | —    |

A decision that records is back on the same order as one that recorded nothing:
a grant within a tenth of the old cost, a refusal within a fifth, against a
fourteen-fold regression on the mechanism this replaces. Stated exactly rather
than rounded up to parity — the evaluation halved, the record spends the
difference, and what is left over is a few hundred nanoseconds either way,
inside the run-to-run spread of three interleaved runs.

**5. `AuthorizationBench` gets the subject that would have caught this.**
`benchGateAllowsRecordingDecisions` builds a Gate the way `AuthWiring` builds one
and asserts a 20-microsecond budget — five times the measured cost, and a third
of what a synchronous write inside the decision costs. A change that puts the
chained write, an event dispatcher, or anything else the application supplies
back inside `allows()` fails a benchmark instead of a deployment.

## Alternatives considered

**Keep the dispatcher and catch listener exceptions inside the Gate.** Rejected.
It fixes the `500` and leaves everything else: an unrelated slow listener still
slows every authorization check, re-entry is still real, and what runs inside a
decision is still a property of the application's listener registry rather than
of the wiring. The cost measurement above is mostly not the exception handling.

**Keep the dispatcher and filter to the two authorization event types before
dispatching.** Rejected. There is nothing to filter on: the Gate dispatches an
`EventEnvelope`, and `ListenerProvider` resolves listeners by the dispatched
object's class, so every envelope listener matches whatever the envelope wraps.
Filtering would mean the Gate reaching into the provider to decide which of the
application's listeners it approves of, which is a worse coupling than the one it
replaces.

**Write the audit entry synchronously and accept the cost.** Rejected as the
default, shipped as `decision_audit_buffer: 1`. A chained write is ~20
microseconds against a ~2 microsecond decision, on a path a request crosses once
per protected resource; making every deployment pay it to close a window most do
not care about is the wrong default. Making it unavailable to the deployments
that do care would be worse, so the threshold is configuration and its meaning is
documented where the operator sets it.

**Flush from a `TerminableMiddlewareInterface` rather than the terminate event.**
Rejected: nothing runs terminable middleware. `Kernel::terminate()` dispatches
`TerminateEvent` and returns; the interface has no caller in `src/` at all. A
flush hung there would be the same unreachable control this ADR's predecessor was
written about. (That the interface exists and nothing runs it is a separate
defect, noted here because it was found while looking for a hook and is not fixed
by this ADR.)

**Drop the events and record only the decision.** Rejected. `AuthorizationGranted`
and `AuthorizationDenied` are `#[Api(since: '1.0.0')]`, the payload hash the
entry cites is computed over the envelope that wraps them, and an application may
legitimately want to construct or replay one. Not dispatching them is a
different thing from not having them.

## Consequences

A listener registered for `AuthorizationGranted` or `AuthorizationDenied` is no
longer called. Nothing released ever called one — the dispatcher argument was
never passed before ADR-0048 — but an application written against that ADR's
description must move its listener to an
`AuthorizationDecisionSinkInterface` binding, which the container now honours in
preference to the framework's own sink.

An audit entry's `timestamp` is when it was written; `decided_at` in its metadata
is when the decision was reached. With the default threshold those differ by the
remainder of a request. A reader comparing them is reading flush latency, and the
documentation says so beside the table of keys.

A hard process death — a segfault, an OOM, a `kill -9` — between a decision and
its flush loses the decisions still buffered. Bounded by the threshold, by the
terminate flush, by the destructor, and removable by setting the threshold to 1.
It is a real trade and it is stated rather than hidden: an ordinary uncaught
exception does not lose them, because the kernel renders it into a response and
the process exits normally, running both the terminate flush and the destructor.

`AuthorizationConfig` gained a fourth constructor parameter and
`config/security.php` a `decision_audit_buffer` key. Both are additive; a config
file that does not mention it gets 64.

Anything constructing `Gate` directly with a third positional argument now passes
a sink where it passed a dispatcher. `Gate` is not `#[Api]` and appears in the
public snapshot only through `GateInterface`, which is unchanged.

## Security impact

The audit trail keeps every property ADR-0048 claimed for it — HMAC chaining,
payload hash, CSPRNG nonce — and gains the correlation id of the request the
decision was made in, which the previous mechanism recorded as a freshly minted
random value that correlated nothing.

Two failure modes are removed. An authorization outcome can no longer be changed
by code that has nothing to do with authorization, which was a denial-of-service
reachable by anything that could register a listener. And an audit trail can no
longer end up holding nested decisions without the outer one that caused them,
which was what re-entry produced on its way to `StormGuard`.

One is added and bounded: decisions buffered at the moment of a hard process
death are lost. See Consequences.

## Performance impact

A grant costs 4.3 µs where it cost 4.0 µs before anything was recorded, a refusal
4.6 µs where it cost 3.9 µs, and either costs 58 µs on the mechanism this replaces.
The dispatcher's cost grew with the number of listeners registered anywhere in
the application; the sink's does not.
`AuthorizationBench::benchGateAllowsRecordingDecisions` holds a 20 µs budget.

Measurement: PHP 8.5.9 ZTS, xdebug off, opcache on, 8 000 revolutions per round,
every variant timed once per round so machine drift lands on all of them, median
across three runs of 15-31 rounds. The `LegacyGate` the "before" rows measure is
the class as `git show HEAD` has it, loaded alongside the current one in the same
process, so the two are compared under one set of conditions rather than across
two.

## Migration / rollback plan

No application change is required. A deployment that needs the pre-flush
durability window closed sets `auth.authorization.decision_audit_buffer` to `1`.
An application that had registered a listener for the authorization events under
ADR-0048 binds an `AuthorizationDecisionSinkInterface` instead.

Rollback is reverting to the dispatcher wiring, which reinstates both defects
above; the benchmark budget would fail first.

## Links

- [ADR-0048](0048-a-guard-is-something-a-request-runs-into.md) — the finding this
  keeps and the mechanism it replaces
- [docs/authorization.md](../authorization.md#decision-audit-trail)
- `tests/Unit/Auth/Authorization/GateDecisionRecordingTest.php`
- `tests/Unit/Auth/Internal/Authorization/BufferedAuthorizationDecisionSinkTest.php`
- `tests/Unit/Core/Wiring/AuthorizationDecisionAuditWiringTest.php`
