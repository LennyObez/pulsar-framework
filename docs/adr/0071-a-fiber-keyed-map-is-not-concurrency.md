# ADR-0071: A fiber-keyed map is not concurrency

## Status

Accepted. **Supersedes [ADR-0005](0005-synchronous-core-controlled-fiber-usage.md)**
(synchronous core with controlled Fiber usage). Keeps the synchronous execution model
exactly as ADR-0005 decided it and replaces the rule ADR-0005 wrote about Fibers, which
counted the wrong thing and had no enforcement. Adds one test
(`tests/Unit/Concurrency/FiberSurfaceInventoryTest`) and no production code. Adds
nothing to the `#[Api]` surface.

## Context

ADR-0005 permitted Fibers in exactly two named subsystems and said, in its Decision,
"Fibers are not used in request business logic, middleware, controllers, or extension
code."

Fifteen files under `src/` and `extensions/` called the Fiber API when this record was
opened. Several of them are on the request path, and one of them is extension code.

That is not fifteen subsystems doing concurrency. Sorted by what they actually do, with
the two that this record **removes** from the surface rather than permits already struck
out of it — which is why the roles below add up to thirteen:

| Role                                         | Count | Files                                                                                                                                                                                                          |
| -------------------------------------------- | ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Creates and drives fibers                    | 2     | `src/Runtime/Fiber/FiberScheduler.php`, `src/Concurrency/FanOut.php`                                                                                                                                           |
| Suspends the current fiber                   | 1     | `src/Runtime/Fiber/CooperativeSleep.php`                                                                                                                                                                       |
| Reads `Fiber::getCurrent()` and nothing else | 10    | `AuthenticationState`, `Gate`, `RequestContextHolder`, `RouteContext`, `CsrfBindingContext`, `TenantContext`, `SystemContext`, `StickinessContext`, `ViewComposers`, and Studio's `FiberScopedContextProvider` |

`src/Security/Audit/AuditLogger.php` and `src/Compliance/Verification/EvidenceChain.php`
were the other two suspenders. Each held what it called a cooperative mutex over its HMAC
hash chain by spinning on a bare `Fiber::suspend()`. That is not a mutex, for the reason
decision 2 sets out, and both now refuse a second entrant instead of waiting for one.
Neither file touches the Fiber API any more.

**The ten are the point of this record.** They create nothing, suspend nothing and
schedule nothing. Each keeps per-execution state in a `WeakMap` keyed by
`Fiber::getCurrent()`, with a stable root object standing in when no fiber is active.
They call the Fiber API in order to be _safe from_ fibers — so that a request's identity,
tenant, CSRF binding or authorization decision cannot bleed into another fiber's, whether
that fiber came from the runtime, from `FanOut`, or from a third-party test harness that
wrapped the call.

Counting those as "subsystems using Fibers" makes the number look like an execution-model
change. It is the opposite: it is the tax the rest of the framework pays for the two
schedulers existing at all. ADR-0005 permitted the two and never mentioned the tax, so a
reader auditing the tree against the ADR finds thirteen violations that are not
violations, and misses the one thing that genuinely moved.

What genuinely moved, since ADR-0005 was written:

- **`FanOut` exists and is `#[Api(since: '1.0.0')]`.** ADR-0005 knew one scheduler; there
  are two. `FanOut` is the bounded round-robin primitive whose per-fiber exception
  isolation ADR-0005 attributed to `FiberScheduler`, which is in fact the persistent
  runtime's accept loop and does a different job.
- **A fiber can be suspended from inside `kernel->handle()`.** `CooperativeSleep` is what
  `CachePool`'s stampede poll, `FilesystemLock` and `RedisLock` call between retries.
  Inside a fiber a scheduler is actively driving, it parks on a `FiberDelay` timer;
  everywhere else it falls back to `usleep`. So request-path code does reach the Fiber
  API — through one call site, deliberately, and only when a lock is contended.
- **`--concurrency N` no longer means what ADR-0005 said.** ADR-0005 approved "Fibers for
  accepting multiple connections". `PersistentRuntime` now refuses any
  `fiber_concurrency` above 1 at **construction**, with
  `RuntimeException::unsafeFiberConcurrency()`, because `RequestSandbox` isolates a
  request from the _next_ one and not from a _concurrent_ one — `ScopeManager`'s instance
  pool, `SessionManager`, the `CacheManager` tag memo and the evicted `SecurityContext`
  all cross. That refusal is a runtime guardrail; ADR-0005 listed runtime guardrails as
  "a future consideration".
- **Extensions do use fibers, and are told to.** `docs/async-model.md` instructs an
  extension singleton holding request state to key it by `Fiber::getCurrent()`, exactly as
  the framework's own holders do, because that is what makes it safe under `FanOut` and
  under a harness. Studio does. ADR-0005 flatly prohibited extension Fiber code.
- **"Enforcement is via code review and architecture tests."** There were no architecture
  tests for any of this. Thirteen files joined the surface without one signal, over the
  whole RC phase, which is
  [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)'s
  subject precisely: a rule whose violation produces nothing is not a rule.

`docs/async-model.md` has been accurate about all of this for some time. The ADR is the
document that drifted, and the ADR is the one a reviewer is entitled to cite.

## Decision drivers

1. The synchronous execution model is the product promise for a regulated-domain
   framework, and it is intact. What needs replacing is a rule about a mechanism, not the
   model.
2. A permission list phrased as "these two subsystems" cannot express the actual rule,
   because the actual rule is about _roles_, and ten of the fifteen participants take a
   role ADR-0005 did not know existed.
3. A rule with no failing check is not a rule. This one had none, and the drift proves it.

## Decision

**The execution model is unchanged and restated: Pulsar is a synchronous
request/response framework. No event loop, no implicit parallelism, no hidden task
scheduler. One request in, one response out. The scheduler evaluates due jobs
sequentially; a worker runs one job to completion before popping the next; console
commands run start to finish.**

**Fibers are governed by role, not by subsystem, and the roles are these three.**

### 1. Creating and driving fibers — two components, and a third is an ADR

`FiberScheduler` (`src/Runtime/Fiber/`) drives the persistent runtime's connection loop.
`FanOut` (`src/Concurrency/`) is a bounded, wall-clock-limited round-robin scheduler for
infrastructure fan-out — preflight checks, health probes, cache warming — with per-task
exception isolation.

Neither multiplexes application code. `FanOut` is `#[Api]` and callable, but
`docs/async-model.md` tells extensions not to reach for it, and the reason is written
there: a service holding request state that is not fiber-keyed will see that state shared
across every task in the fan-out.

A third scheduler is a change to the execution model and needs its own record.

### 2. Suspending the current fiber — one call site, and what it may assume

`CooperativeSleep` suspends with a `FiberDelay` **only** when a scheduler is driving
(`FiberScheduler::isDriving()`); otherwise it calls `usleep`. It is what `CachePool`'s
stampede poll, `FilesystemLock` and `RedisLock` call between retries, and it is the only
code in `src/` or `extensions/` that calls `Fiber::suspend()` from outside a scheduler's
own body. What it may assume is exactly what that guard establishes: a scheduler is
driving this fiber, and it understands `FiberDelay`. It assumes nothing else, and a
caller that cannot make the same two statements may not suspend.

Suspending from anywhere else — a controller, middleware, a listener, a job — is
forbidden. `ContainerInterface` carries the same prohibition for factory closures.

#### Why a bare `Fiber::suspend()` is not a scheduling primitive

`Fiber::suspend($value)` does one thing: it returns control to whoever started or resumed
the fiber, handing them `$value`. It states no condition. What the fiber is waiting _for_
is decided entirely on the other side, and the two schedulers in this tree answer
differently:

- `FiberScheduler` reads a suspend carrying a `FiberDelay` as "resume me at this
  deadline", and a suspend carrying **no** value as "resume me when my connection socket
  becomes readable".
- `FanOut` ignores the value entirely and resumes every live fiber on its next
  round-robin pass, whatever the fiber believed it was waiting for.
- With no scheduler driving, there is no answer at all: outside a fiber `Fiber::suspend()`
  raises `FiberError`, and inside a fiber nobody is polling it parks until the process
  ends.

So a bare suspend can express "I yield". It cannot express "wait until P", because
nothing in the call carries P to the party that will resume. Code deep inside
`kernel->handle()` cannot know which of the three answers it is about to get, and it is
not that code's business to know — which is why the permission is written as "suspend
only where you can name the scheduler and the protocol", and not as a list of callers.

`AuditLogger` and `EvidenceChain` are the evidence for this clause, and are why the two
of them left the surface. Each spun on a bare `Fiber::suspend()` as a mutex over its hash
chain, guarded by `if (Fiber::getCurrent() !== null)`, and it failed in both directions.
Inside a fiber under `FiberScheduler` the wait was answered by socket readability — an
unrelated event — or by nothing at all, on the tamper-evidence mechanism of a compliance
framework. Outside a fiber the guard skipped the wait altogether, so the entrant that
actually occurs, a nested `log()` from an audit sink or an evidence store recording
something of its own, walked straight into the window the wait existed to close. Two
writers that read the same predecessor hash append two records claiming one position, and
`verify()` reports the file as tampered with.

No protocol repairs that, because there is no execution context that both holds the chain
and can be resumed by the code waiting on it. Under this record a second entrant to a
chain advance is always a defect, so each class now refuses it where it stands — with
`SecurityException::auditChainAdvanceReentered()` and `ConcurrentEvidenceAppendException`
respectively — rather than parking on a condition nothing may ever satisfy.

### 3. Reading `Fiber::getCurrent()` for state isolation — encouraged, everywhere

Any component holding per-execution state **should** key it by `Fiber::getCurrent()` in a
`WeakMap`, with a stable root object when no fiber is active. Ten framework and extension
components already do. This adds no concurrency and no ordering change: outside a fiber
it behaves exactly like a plain per-process holder, which is the normal case for FPM and
CLI, and it costs one null check.

Extensions **should** do the same with their own request-state singletons. This reverses
ADR-0005's blanket prohibition on extension Fiber code, which would have made an
extension's state _less_ safe than the framework's.

### Concurrency stays capped at one request

`fiber_concurrency` accepts 0 (synchronous accept loop, the default) and 1 (one
connection fiber, which never interleaves because `FiberScheduler::hasCapacity()` refuses
a second). Anything higher is refused at construction. Scale a persistent deployment with
multiple worker processes behind a load balancer.

### The surface is pinned by a test

`tests/Unit/Concurrency/FiberSurfaceInventoryTest` tokenises every non-test PHP file under
`src/` and `extensions/`, strips comments, and asserts that the set of files calling
`new Fiber(...)`, `Fiber::suspend()` or `Fiber::getCurrent()` — and the role each plays —
is exactly the thirteen above. A file that joins the surface fails the test with the role
it took on, and a file that leaves it fails just as loudly: it is what refused the removal
of `AuditLogger` and `EvidenceChain` until this record and `docs/async-model.md` had been
updated to match. It has been observed refusing a planted entry.

## Alternatives considered

### Keep ADR-0005 and add the thirteen missing subsystems to its list

Rejected. The list would then hold ten entries whose only fiber interaction is defensive,
presented as permissions to use a concurrency feature. The next reader would draw exactly
the wrong conclusion from the length of the list, which is what happened here.

### Forbid `Fiber::getCurrent()` outside the runtime and pass context explicitly

This is the honest alternative and it was considered seriously. Threading a context object
through every middleware, controller, listener, view composer and query observer would
remove ten files from the surface.

Rejected on two grounds. It cannot be enforced across extension and application code,
which is where the leak would actually occur; and it would not help, because the state
that must not cross is held by singletons (`ScopeManager`, `SessionManager`) that no
signature change reaches. The `WeakMap` is not a workaround for a missing parameter — it
is the only mechanism that survives a fiber created by code the framework does not own.

### Remove the two schedulers and drop fibers entirely

Rejected. `FiberScheduler` is what lets the persistent runtime's accept loop keep running
while a connection is parked, and `CooperativeSleep` is what turns a contended cache lock
into a yield instead of a blocked worker. Removing them would cost throughput on the
persistent runtime to delete a mechanism that is already capped at one request in flight.

### Write the rule and skip the test

Rejected by ADR-0060, and by the evidence: the previous rule was written and skipped, and
thirteen files drifted past it without a sound.

## Consequences

### Positive

- **The rule now describes what code does.** "Creates", "suspends", "reads current" are
  checkable properties of a file. "Approved subsystem" was not.
- **Drift fails a test.** A sixteenth file joining the surface stops a build and names
  itself and its role, instead of being discovered by an audit two releases later.
- **Extension state gets safer.** Telling extensions to key request state by fiber, rather
  than forbidding them the API, closes the leak ADR-0005's prohibition would have left
  open under `FanOut`.

### Negative

- **The inventory has to be maintained.** Every legitimate new fiber-keyed holder is one
  more line in a test table and one more row in `docs/async-model.md`. That is deliberate
  friction — the cost of a holder that is _not_ legitimate is a cross-request state leak —
  but it is friction, and it will occasionally be paid on a change that deserved none.
- **Three levels of nuance where ADR-0005 had one sentence.** "Two approved subsystems"
  fits in a reviewer's head. "Thirteen files in three roles, ten of them defensive" does
  not, and the test is now doing work the sentence used to pretend to do.
- **`FanOut` is `#[Api]` and discouraged in the same breath.** An extension author can
  call it, is told not to, and is stopped by nothing. Narrowing it to `#[Internal]` during
  the RC phase would break a marked-stable surface; the honest position is that this is a
  documented convention and not a control.

### Neutral

- **Nothing in the execution model changes.** The guarantees in `docs/async-model.md` —
  no concurrent requests, no hidden scheduler over application code, deterministic
  ordering, RAII safety, no implicit parallelism, `WeakMap` reclamation — hold exactly as
  before, because no code changed.
- **The zero-cost claim survives.** Outside a fiber, every holder in the third role takes
  one null check and uses the root key; `CooperativeSleep` calls `usleep`. ADR-0005's
  "zero-cost Fiber path" is still true, and is now true of ten more files than ADR-0005
  knew about.

## Security impact

No change to the attack surface; no production code changed. Two properties are worth
stating because this record is where a reviewer will look for them.

- **Cross-request state isolation is the reason the third role exists.** `Gate`,
  `AuthenticationState`, `CsrfBindingContext`, `TenantContext` and `SystemContext` hold
  material whose leak across executions is an authorization or tenancy defect, not a
  correctness one. Fiber-keying them is a security control, and the new test is what
  keeps a future holder from being added without it.
- **The concurrency cap is a security control too.** `fiber_concurrency > 1` is refused
  because `SessionManager` is a plain singleton: the second interleaved request finds the
  session already started and reads the first caller's data. The refusal is at
  construction, so a misconfigured deployment fails to start rather than serving one
  user's session to another.

## Performance impact

None measured or expected; no production code changed.

For the reader sizing the third role: `Fiber::getCurrent()` plus a `WeakMap` lookup is on
the request path in `RequestContextHolder`, `RouteContext`, `Gate` and
`AuthenticationState`. Those paths are covered by `KernelBench` (pre-booted dispatch
asserted under 400 µs) and `AuthorizationBench` (gate check under 50 µs), both hard-gated
per [ADR-0072](0072-a-budget-is-the-assertion-that-runs.md).

## Migration / rollback plan

Nothing to adopt. An extension already keying request state by fiber is doing the right
thing; one that is not was already unsafe under `FanOut` and should start.

To roll back: delete `tests/Unit/Concurrency/FiberSurfaceInventoryTest`. The rule would
survive as prose and stop being enforced, which is the state this record exists to leave.

## Links

- [ADR-0005](0005-synchronous-core-controlled-fiber-usage.md) — the superseded rule; its
  Context is still the argument for a synchronous core
- [ADR-0010](0010-persistent-worker-runtime-request-sandbox.md) — the persistent runtime
  and `RequestSandbox`, whose isolation boundary is why concurrency is capped at one
- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md) —
  why the inventory is a test and not a paragraph
- `docs/async-model.md` — the full inventory, the guarantees, and the extension rules
- `tests/Unit/Concurrency/FiberSurfaceInventoryTest.php` — the guard
