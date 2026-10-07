# ADR-0057: A guard that is not call-scoped guards nothing

## Status

Accepted. Amends [ADR-0052](0052-an-authorization-decision-does-not-run-the-application.md),
whose decision 1 stands unchanged and whose decisions 2, 3 and 5 were true of
the design and false of the code. Adds two `#[Internal]` classes
(`Pulsar\Auth\Authorization\DecisionRecordingState`,
`Pulsar\Auth\Internal\Authorization\AuthorizationDecisionFlushListener`),
renames one `#[Internal]` constructor parameter, and changes the default of one
shipped config key.

## Context

ADR-0052 took the application out of the authorization decision. It replaced the
event dispatcher with a single named sink, said the Gate contains a sink that
misbehaves, said the chained write happens after the decision has been returned,
and said the destructor cannot turn a clean exit into a fatal. Four of those
claims did not hold against the code that implemented them.

### The re-entry guard was a property of the Gate, not of the call stack

`Gate::record()` guarded re-entry with `private bool $recording`, plus
`private array $nested` and `private int $refused`. Those answer "is this Gate
recording anywhere", which is the same question as "is this call stack inside a
record" only in a process that runs one call stack.

Pulsar does not. `FiberScheduler` interleaves Fibers on one worker, and the sink
`AuthWiring` binds reaches `AuditLogger`, which spun cooperatively on its chain
lock — `while ($this->chainLocked && Fiber::getCurrent() !== null)`. A sink that
suspended inside `record()` was not hypothetical; it was what the shipped sink did
under contention.

> **Note, 1.0.0-rc.12.** That mechanism is gone. `AuditLogger` no longer touches the
> Fiber API at all: it refuses a second entrant outright with
> `SecurityException::auditChainAdvanceReentered()` instead of waiting for the first
> to finish. [ADR-0071](0071-a-fiber-keyed-map-is-not-concurrency.md) section 2 records
> why — a bare `Fiber::suspend()` is not a scheduling primitive, and the guard skipped
> the wait entirely for non-fiber callers, which is every caller in every deployment
> that is not running the persistent runtime.
>
> **The decision below is unaffected and does not change.** The Gate's `WeakMap` guard
> is still keyed by call rather than by instance, and still must be: a sink can still
> throw, and can still be re-entered by a nested authorization check inside a single
> call stack. The argument for call scope never depended on this particular sink
> suspending — that was the sharpest illustration available at the time, not the
> premise. What follows is the record as written, with the illustration now in the past
> tense.

With the guard on the instance, a decision reached by Fiber B while Fiber A's
sink was suspended read as B re-entering A's record. Measured by execution, with
a sink that suspends where the audit logger suspends:

- **Cross-attribution.** B's decision was handed to the sink from A's drain, on
  A's call stack. The test that now covers this failed as
  `Failed asserting that 1207 is identical to 1216` — two different Fibers, one
  of them recording the other's decision.
- **Loss.** Twenty Fibers deciding while the first one's sink was suspended
  produced seventeen records. Sixteen fitted the nested queue, three were
  refused past `MAX_NESTED_DECISIONS`, and A's `finally` then cleared the queue
  outright. `user-17`, `user-18` and `user-19` were authorized and never
  recorded.

The ceiling exists to bound what one sink may cause from inside one record. It
was bounding how many Fibers may decide at once, and the excess was not queued
for later — it was dropped.

### The report was made through code that can throw

Both reports were `$this->logger?->critical(...)`, one inside the `catch` that
contains a throwing sink and one inside the `finally` that closes the frame. The
logger is application-supplied. A logger that throws therefore put the
sink's fault back on the caller one frame further out — and from the `finally`
it would additionally replace whatever exception was being unwound. All three
paths were reproduced: `RuntimeException: log target unavailable` surfacing out
of `Gate::allows()` for a grant, for a refusal, and for a decision whose nesting
was refused.

That is the defect ADR-0052 exists to remove, with the logger as the trigger
instead of the sink.

### The flush was inside the decision on every shipped deployment

`BufferedAuthorizationDecisionSink::record()` called `flush()` as soon as the
buffer reached `$flushThreshold`, and the shipped threshold was 64. A request
that authorizes more than 64 actions — a listing page checking a permission per
row — paid for 64 HMAC-chained entries inside one arbitrary `Gate::allows()`.
Measured over 300 decisions at the shipped configuration, **256 entries were
written before any drain point ran**, all of them inside a decision.

ADR-0052's decision 3 says the entries "are built and chained by `flush()`,
after the decision has been returned". For 64 decisions out of every 64 that was
true, and for the sixty-fourth it was the opposite.

### `pulsar optimize` failed on the framework's own wiring

`AuthWiring` registered the terminate flush as a closure. `EventMapCompiler`
compiles a listener to a class and a method so `CompiledListenerProvider` can
resolve it from the container, and a closure has neither:

```
$ php bin/pulsar optimize
  Config: cached
  Routes: 124 cached, 0 skipped
  Container: cached
[ERROR] Error: Invalid event listener: closures cannot be compiled; use an
        invokable class or [class, method] array
```

The closure was registered whenever an audit logger existed, which is every
deployment that records its decisions at all. The command that exists to prepare
a production deployment failed on the framework, before reaching anything the
application had written.

### Two consequences ADR-0052 states as facts

**The destructor could turn a clean exit into a fatal.** ADR-0052: "`flush()`
reports every write failure and raises none, so running it during shutdown
cannot turn a clean exit into a fatal one." `flush()` reported through
`$this->logger?->critical(...)`, and `write()` did the same from its `catch`. A
logger that throws during shutdown raises from `__destruct()`, which is a fatal.

**The benchmark measured a configuration the framework does not ship.**
`AuthorizationBench::benchGateAllowsRecordingDecisions` built its sink with
`flushThreshold: 1_000_000`. No `config/security.php` ever contained that
number; the shipped one was 64. The subject ADR-0052 names as the guard against
putting the write back inside `allows()` measured a sink that could not reach
its threshold, while the shipped sink reached it fifteen times per benchmark
iteration.

## Decision

**1. The recording guard is keyed by Fiber, the way every other piece of
per-call-stack state in this framework is.**

`Gate` holds a `WeakMap<object, DecisionRecordingState>` keyed by
`Fiber::getCurrent() ?? $rootKey` — the pattern `RequestContextHolder`,
`TenantContext`, `RouteContext`, `StickinessContext`, `SystemContext`,
`CsrfBindingContext` and `AuthenticationState` already use. Nothing new was
invented for this: the framework had one answer to "state that belongs to this
call stack" and the Gate was not using it.

A Fiber's call stack is linear, so per-Fiber is per-call-stack: finding an open
state can only mean this frame is nested inside this Fiber's own outer one. The
queue, the refusal count and the ceiling become properties of one decision
rather than of the Gate, and the frame closes by removing its state rather than
by clearing fields another Fiber is using. Concurrency stops being reported as
re-entry, and stops being dropped as if it were.

The `WeakMap` reclaims a Fiber's slot when the Fiber is collected, so an
abandoned recording cannot pin memory.

**2. Nothing on the reporting path can raise.**

`Gate` and `BufferedAuthorizationDecisionSink` each gained a private
`reportCritical()` that wraps the logger call in its own `try`/`catch
(Throwable)`. A failed report is dropped, because the only channel for saying
"the log could not be written" is the log, and the decision has already been
reached correctly and returned — which is the property the containment exists to
protect. `__destruct()` additionally wraps `flush()`, so the claim that
destruction cannot turn a clean exit into a fatal is now true of everything in
the destructor rather than of the parts that were checked.

**3. `record()` does not write. Drain points do.**

The threshold stops being a batch trigger and becomes what its config key always
described: how many decisions the sink may hold. The entries are chained at
points that are outside every decision by construction, and `AuthWiring`
registers one listener on three of them — the kernel's `TerminateEvent`, and the
queue's `JobCompleted` and `JobFailed`, which are what a worker reaches instead
of `terminate()`. The sink's destructor is the fourth and covers a console
command and a runtime that never calls `Kernel::terminate()`.

The capacity is a memory ceiling behind those, not a fourth drain point.
Reaching it means one of them should have run and did not, so the sink says so
at `critical` once, and then writes exactly one entry — the oldest — per further
decision. Memory stays pinned at the capacity, the trail stays in order, and
what lands inside a decision in that state is one chained write rather than a
whole buffer. `decision_audit_buffer: 1` keeps its meaning exactly: capacity one
is write-through, chosen by an operator who will not accept a durability window,
and it is not reported because it is not a defect.

The shipped default rises from 64 to 1024, which is what makes "the write is not
inside a decision" a property of the shipped configuration rather than a
property of its first 64 decisions. A buffered decision is six scalars and a
context reference; a full buffer is a few hundred kilobytes.

**4. The drain listener is an invokable class.**

`AuthorizationDecisionFlushListener` takes the sink and ignores the event —
what a drain needs is the moment, and the moment is being called at all. It is
bound in the container under its own class name, because that is what the
compiled map resolves through: a listener the container built instead would hold
a different sink. It accepts `object` rather than naming `TerminateEvent`,
`JobCompleted` and `JobFailed`, which keeps the auth module from depending on
the kernel's and the queue's event types for a parameter it does not read.

`pulsar optimize` now compiles the three drain points and succeeds.

**5. `AuthorizationBench` measures what `AuthWiring` builds.**

The recording subject's capacity comes from
`AuthorizationConfig::DEFAULT_DECISION_AUDIT_BUFFER`, so the benchmark cannot
drift from the shipped value again, and its revolution count is set below that
capacity so one iteration is one unit of work drained between iterations —
which is where the framework drains it.

## Alternatives considered

**Keep the guard on the instance and take a lock.** Rejected. The Gate has no
lock to take that a cooperative scheduler would honour, and the thing being
guarded is not a shared resource — it is "am I already inside my own record".
That question has an answer per call stack and no answer per instance, so a lock
would serialise Fibers to make a wrong question look right.

**Give the sink a per-Fiber buffer.** Rejected. The buffer is not call-scoped
state; it is a queue of work the process owes the audit chain, and the drain
points are per process and per unit of work. Splitting it per Fiber would give
each Fiber its own chain-ordering and make a terminate flush drain only the
Fiber that happened to run it.

**Flush from the Gate after `record()` returns.** Rejected: `record()` is the
last thing `allows()` does, so "after `record()`" and "inside `allows()`" are
the same instant to a caller. Moving the flush to the top of the next `allows()`
has the same property with the cost landing on a different decision.

**Keep the threshold flush and raise the number.** Rejected as the whole fix. It
makes the defect rarer without removing it: a unit of work above the number
still pays for a whole buffer inside one decision. The number is raised _and_
the batch trigger is gone; either alone leaves the claim in ADR-0052 untrue for
some deployment.

**Drop the capacity entirely and rely on drain points.** Rejected. A console
command that authorizes a million rows without reaching a drain point would
buffer a million decisions. Unbounded memory growth reachable from ordinary
work is a denial of service, and this framework's deployments are the ones that
can least afford one.

**Register the flush as `[$sink, 'flush']` instead of an invokable class.**
Rejected. It compiles, but it makes the compiled map call `flush($event)` with
an argument the method does not declare, and it names a method whose contract is
"drain the buffer" as though it were a listener contract. The listener is the
thing that knows a drain point was reached; the sink is the thing that drains.

## Consequences

`decision_audit_buffer` means capacity rather than batch size. A deployment that
set it explicitly keeps whatever it set, and its meaning has not inverted — the
sink still writes at that number — but what it writes there is one entry rather
than the buffer. A deployment on the default now buffers up to 1024 decisions
instead of 64 between drain points.

What a hard process death can lose is now bounded by the distance to the next
drain point rather than by 64: for a served request that is the request, for a
queue worker the job, and 1024 is the ceiling behind both. For any unit of work
under 1024 decisions — which is all of them, in practice — the window is
unchanged, because the terminate flush was already what emptied it. For a unit
of work above 64 the window grows, and that is stated rather than hidden: the
trade buys the removal of a multi-millisecond stall from the authorization path.
A deployment that will not accept any window still sets `1`.

`Gate` allocates a `WeakMap` and a root key per instance. `Gate` is a singleton
in the composition root.

Anything constructing `BufferedAuthorizationDecisionSink` by named argument
passes `capacity:` where it passed `flushThreshold:`. The class is `#[Internal]`
and appears in no public API snapshot.

A queue worker now flushes its authorization decisions after every job, which it
did not before. `JobCompleted` and `JobFailed` appear in the compiled event map
of every deployment with an audit logger, whether or not it runs a queue.

## Security impact

Two ways of losing an authorization record are removed, and both were reachable
without an attacker: concurrent decisions dropped past the nesting ceiling, and
a decision lost because the process died holding a buffer no drain point could
reach. A third is removed at the boundary — an authorization outcome can no
longer be changed by the application's logger, which was a denial of service
reachable by anything that could make logging fail.

Cross-attribution is the one an assessor would have found hardest to explain: a
record naming the right identity but produced from another request's call stack,
counted against another request's ceiling, and reported under another request's
permission in the `critical` line. The record's contents were always its own;
what was wrong was everything the Gate said _about_ it.

The buffered window grows for units of work above 64 decisions. See
Consequences.

## Performance impact

Measured on one machine (PHP 8.5.9, xdebug off, opcache on), as a request: a run
of decisions, then the drain the kernel performs on terminate. The drain is not
counted against a decision, because it is not one. 400 requests per variant,
medians across requests; `LegacyThresholdSink` reproduces the shipped `record()`
of the code this ADR amends.

An ordinary request — eight decisions:

| Gate                               | mean/decision | worst decision | authz per request |
| ---------------------------------- | ------------- | -------------- | ----------------- |
| Evaluation alone, no sink          | 2.27 µs       | 3.8 µs         | 18.2 µs           |
| This ADR                           | 5.17 µs       | 6.6 µs         | 41.4 µs           |
| The code this amends, threshold 64 | 6.58 µs       | 10.7 µs        | 52.6 µs           |
| `decision_audit_buffer: 1`         | 62.56 µs      | 75.5 µs        | 500.5 µs          |

A listing page — two hundred decisions:

| Gate                               | mean/decision | worst decision | authz per request |
| ---------------------------------- | ------------- | -------------- | ----------------- |
| Evaluation alone, no sink          | 2.69 µs       | 13.4 µs        | 538.6 µs          |
| This ADR                           | 6.31 µs       | 36.6 µs        | 1.26 ms           |
| The code this amends, threshold 64 | 60.52 µs      | 4.34 ms        | 12.10 ms          |
| `decision_audit_buffer: 1`         | 75.94 µs      | 417.8 µs       | 15.19 ms          |

The ordinary request is parity. The listing page is the defect: one decision in
sixty-four paid 4.34 milliseconds, and the request's authorization path cost
12.1 milliseconds against 1.26. That is a 9.6× reduction in what the request
pays and a 119× reduction in the worst single decision, for the same total work
— the entries are still written, at a point where nothing is waiting on them.

The per-Fiber guard costs nothing measurable. A Gate with a sink that does
nothing, 20 000 revolutions × 9 rounds: 5.23 µs per decision with the `WeakMap`
keyed by `Fiber::getCurrent()`, 5.46 µs with the instance flag, against a
run-to-run spread of 4.96–5.23 µs for repeats of the same build. The difference
between the two is inside the noise of either.

## Migration / rollback plan

No application change is required. A deployment that had set
`auth.authorization.decision_audit_buffer` keeps its value. A deployment that
needs the pre-drain window closed sets `1`, unchanged.

Anything constructing the `#[Internal]` sink by named argument renames
`flushThreshold:` to `capacity:`.

Rollback reinstates all four defects; `pulsar optimize` would fail first, and
`AuthorizationDecisionAuditWiringTest` and `GateDecisionRecordingTest` before
that.

## Links

- [ADR-0052](0052-an-authorization-decision-does-not-run-the-application.md) —
  the decisions this amends
- [ADR-0071](0071-a-fiber-keyed-map-is-not-concurrency.md) — why per-Fiber state
  is keyed the way it is, and the record that governs it. It superseded
  [ADR-0005](0005-synchronous-core-controlled-fiber-usage.md), which this bullet
  used to name on its own: ADR-0005 permitted Fibers in two named subsystems and
  said nothing at all about the fiber-keyed holders, of which the `Gate` guard
  decided on here is one
- [docs/authorization.md](../authorization.md#decision-audit-trail)
- `tests/Unit/Auth/Authorization/GateDecisionRecordingTest.php`
- `tests/Unit/Auth/Internal/Authorization/BufferedAuthorizationDecisionSinkTest.php`
- `tests/Unit/Core/Wiring/AuthorizationDecisionAuditWiringTest.php`
