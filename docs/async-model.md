# Async model

## What Pulsar IS

Pulsar is a **synchronous request/response framework**:

- **HTTP handling**: One request in, one response out, fully sequential. No two requests are ever in flight at once inside a worker — see [Request multiplexing](#request-multiplexing-and-why-it-is-capped) for the mechanism that enforces it.
- **Scheduler**: Cron-based job scheduling. Each tick evaluates due jobs and runs them sequentially.
- **Workers**: Blocking worker model. A worker pops one job at a time, executes it to completion, then pops the next.
- **Console commands**: Run synchronously from start to finish.

All framework code executes in a single thread with deterministic ordering. There is no implicit parallelism, and no user code is ever run concurrently with other user code.

## What Pulsar is NOT

- **Not an async framework**: Pulsar does not use an event loop library (ReactPHP, Amp, Revolt, etc.), and application code is never written against promises, callbacks, or awaitables.
- **Not implicitly parallel**: No framework code creates threads, forks processes, or runs background tasks behind the caller's back.
- **Not a general-purpose Fiber runtime for application code**: Fibers do exist in the framework — two schedulers, and a handful of places that suspend inside them or key state by Fiber identity. All of it is infrastructure, and [the next section inventories every one](#where-fibers-are-used). None is reachable from a controller, middleware, listener, or extension without writing Fiber code by hand, which the [extension guidelines](#extension-guidelines) forbid.

If you need concurrent I/O, use external workers (queue jobs, separate processes, or dedicated async runtimes). Pulsar handles the request path; concurrency lives outside the request lifecycle.

## Where fibers are used

There are two Fiber **schedulers** in the framework, plus code that suspends inside them and code that keys state by Fiber identity. The complete inventory:

### Components that create and drive fibers

| Component        | File                                   | Role                                                                                        |
| ---------------- | -------------------------------------- | ------------------------------------------------------------------------------------------- |
| `FiberScheduler` | `src/Runtime/Fiber/FiberScheduler.php` | The persistent runtime's connection loop: one Fiber per accepted connection (see cap below) |
| `FanOut`         | `src/Concurrency/FanOut.php`           | Bounded, infrastructure-only round-robin Fiber scheduler (see below)                        |

### Components that suspend the current fiber

| Component          | File                                     | Suspends with                                                 |
| ------------------ | ---------------------------------------- | ------------------------------------------------------------- |
| `CooperativeSleep` | `src/Runtime/Fiber/CooperativeSleep.php` | `FiberDelay` timer, so a lock wait yields instead of blocking |

There is exactly one, and it suspends with a value. `CooperativeSleep` is what the cache stampede poll (`CachePool`), `FilesystemLock` and `RedisLock` call between retries. Inside a Fiber a scheduler is actively driving, it parks the Fiber on a timer; everywhere else it falls back to `usleep`, which is correct there and never risks a Fiber nothing will wake.

`AuditLogger` and `EvidenceChain` used to be in this table, spinning on a bare `Fiber::suspend()` as a cooperative mutex over their hash chains. That is not a mutex. `FiberScheduler` reads a suspend with no value as "resume me when my connection socket becomes readable", so the wait was answered by an unrelated event or by nothing at all; and the wait was guarded by `Fiber::getCurrent() !== null`, so a caller outside a Fiber — the nested case that actually happens, an audit sink or evidence store that records something of its own — skipped it entirely and walked into the window it was meant to be excluded from. Neither class waits now. A second entrant is refused where it stands, with `SecurityException::auditChainAdvanceReentered()` and `ConcurrentEvidenceAppendException` respectively, because there is no execution context that both holds the chain and can be resumed by the code waiting on it. Neither file touches the Fiber API any more.

### Components that key state by fiber identity

These create no Fibers. They store per-execution state in a `WeakMap` keyed by `Fiber::getCurrent()`, with a stable root object standing in when no Fiber is active, so state cannot bleed between Fibers created by anything — the framework, a test harness, or third-party code.

Ten of the eleven call `Fiber::getCurrent()` themselves; `ContextScope` is the exception, carrying a key its provider produced. `tests/Unit/Concurrency/FiberSurfaceInventoryTest` pins the ten (plus the three above) by scanning for the API calls, so this table cannot quietly gain a twelfth entry — see [ADR-0071](adr/0071-a-fiber-keyed-map-is-not-concurrency.md). It is what refused the removal of `AuditLogger` and `EvidenceChain` until this page was updated with them.

| Component                    | File                                                   |
| ---------------------------- | ------------------------------------------------------ |
| `FiberScopedContextProvider` | `extensions/studio/src/FiberScopedContextProvider.php` |
| `ContextScope`               | `extensions/studio/src/ContextScope.php`               |
| `RequestContextHolder`       | `src/Context/RequestContextHolder.php`                 |
| `TenantContext`              | `src/Tenancy/TenantContext.php`                        |
| `SystemContext`              | `src/Tenancy/Guard/SystemContext.php`                  |
| `AuthenticationState`        | `src/Auth/AuthenticationState.php`                     |
| `Gate`                       | `src/Auth/Authorization/Gate.php`                      |
| `RouteContext`               | `src/Http/RouteContext.php`                            |
| `CsrfBindingContext`         | `src/Security/Csrf/CsrfBindingContext.php`             |
| `StickinessContext`          | `src/Database/Routing/StickinessContext.php`           |
| `ViewComposers`              | `src/View/Engine/ViewComposers.php`                    |

### How fiber-keyed context works

`FiberScopedContextProvider` maintains a `WeakMap<object, SplStack<CorrelationContext>>` that maps Fiber identity to a stack of correlation contexts. The other holders in the table above follow the same pattern with their own payload:

1. **Key selection**: When a Fiber is active (`Fiber::getCurrent()` returns non-null), the Fiber instance is used as the WeakMap key. When no Fiber is active, a stable root `stdClass` instance is used instead.
2. **Scope entry**: `enter()` pushes a new `CorrelationContext` onto the stack for the current key and returns a `ContextScope` guard.
3. **Scope exit**: When the `ContextScope` guard is closed (via `close()` or RAII), it pops the context from the stack. The guard enforces that `close()` is called from the same Fiber that called `enter()`.
4. **Garbage collection**: When a Fiber completes and is garbage-collected, its `WeakMap` entry is automatically reclaimed - no manual cleanup required.

### Why fibers here

Studio collectors (middleware interceptors, database query observers, etc.) may run inside Fibers created by third-party code or test harnesses. Without Fiber-scoped context, correlation IDs would leak between unrelated execution contexts. The `WeakMap` approach isolates each Fiber's observability state without requiring callers to pass context explicitly. The framework's own request-state holders key the same way for the same reason.

## Deterministic fallback

When no Fiber is active - which is the normal case for standard PHP-FPM and CLI requests - all context operations use the root `stdClass` key. This means:

- Context stacks behave identically to a simple global stack.
- No Fiber overhead is incurred (no `Fiber::getCurrent()` calls on the hot path beyond the null check).
- Sequential execution order is guaranteed.

The Fiber-aware code path activates only when Fibers are actually present, making the design zero-cost for the common case.

## Request multiplexing, and why it is capped

The persistent runtime (`runtime:serve`) can run its connection handler inside a Fiber. `fiber_concurrency` — the `--concurrency` flag, or `RUNTIME_FIBER_CONCURRENCY` — chooses how many:

| Value | Behaviour                                                                         |
| ----- | --------------------------------------------------------------------------------- |
| `0`   | Synchronous accept loop. No Fiber is created. This is the default.                |
| `1`   | One connection Fiber at a time. The accept loop keeps running while it is parked. |
| `> 1` | **Refused.** The worker throws at construction and does not start.                |

Only one request is ever in flight, and that is deliberate. `RequestSandbox` isolates one request from the **next** one on the worker; nothing isolates a request from a **concurrent** one. With more than one connection Fiber the two interleave — `CooperativeSleep` suspends a Fiber from deep inside `kernel->handle()` whenever a cache lock or stampede poll is contended, and the accept loop then admits the next connection, which runs its own `beforeRequest()`/`afterRequest()` against state the parked request still holds.

State that would cross, and does not fit in a `WeakMap` keyed by Fiber:

- `ScopeManager` keeps one request-scoped instance pool and one active-scope flag per process; the second request empties the first one's pool and closes the scope it is still inside. Its current tenant id is process-wide too.
- `SessionManager` is a plain singleton with no Fiber keying: the second request finds the session already started, skips loading its own, and reads the first caller's data.
- `FlagEvaluationLog` (an audit artifact), the `CacheManager` tag memo, and the evicted `SecurityContext` all reset under the parked request.
- Superglobal and error-state hygiene is process-global by definition.

Making that safe would mean Fiber-keying every `ResettableInterface` singleton in the framework **and** in application code. Rather than ship a `--concurrency` flag that silently leaks one caller's session into another's request, the runtime refuses the setting and names the defect in the error.

Nothing is lost by the cap. The connection handler blocks on every `socket_read()` and `socket_write()`, so a connection Fiber already runs to completion before the accept loop admits the next one — the extra Fibers bought no I/O concurrency, only the interleave. Scale a persistent deployment with **multiple worker processes** behind a load balancer, which is what [`docs/deployment/persistent.md`](deployment/persistent.md) describes.

## FanOut: controlled infrastructure concurrency

`Pulsar\Concurrency\FanOut` is a controlled concurrency primitive for infrastructure-only use. It executes an array of callables concurrently using Fibers with round-robin scheduling and a wall-clock timeout.

### Design constraints

- **Infrastructure only**: FanOut is intended for framework internals (preflight checks, health probes, cache warming). It is NOT a general-purpose concurrency tool for business logic.
- **Cooperative scheduling**: Each callable runs in its own Fiber. The scheduler resumes Fibers in round-robin order. There is no preemptive scheduling.
- **Per-task isolation**: Exceptions in one task do not affect other tasks. Each result captures success/failure independently.
- **Timeout enforcement**: A wall-clock deadline ensures no runaway tasks. Tasks that have not completed when the deadline expires are marked as timed out.

### Usage

```php
use Pulsar\Concurrency\FanOut;

$results = FanOut::run([
    'health' => fn() => $healthChecker->check(),
    'cache'  => fn() => $cacheWarmer->warm(),
    'dns'    => fn() => $dnsResolver->resolve('api.example.com'),
], timeoutMs: 3000);

foreach ($results as $key => $result) {
    if ($result->timedOut) {
        $logger->warning("Task {$key} timed out");
    } elseif (!$result->success) {
        $logger->error("Task {$key} failed", ['error' => $result->error->getMessage()]);
    }
}
```

### When NOT to use FanOut

- **Business logic**: Use sequential execution. If throughput is a concern, dispatch work to the queue system.
- **I/O-bound operations in request path**: Use async workers or external processes instead.
- **Extension code**: Extensions should prefer sequential execution per the concurrency rules below.
- **Anything holding per-request state**: The tasks run inside Fibers, so a service that stores request state without keying it by Fiber will see it shared across every task in the fan-out — the same class of problem that caps `fiber_concurrency`.

FanOut does not violate Pulsar's synchronous execution model: it provides structured, bounded concurrency within a single call frame, with deterministic completion ordering (tasks complete in the order they finish, results are returned in input key order).

## Extension guidelines

### MUST NOT

- **Create Fibers that escape their scope**: An extension must not create a Fiber and allow it to outlive the extension's `boot()` or request-handling lifecycle. Escaped Fibers break deterministic execution guarantees.
- **Start background threads**: PHP's threading model (via extensions like parallel) is not compatible with Pulsar's single-threaded assumptions. Do not use `parallel\Runtime` or similar constructs.
- **Assume implicit concurrency**: Do not write code that depends on concurrent execution. Pulsar provides no scheduling or multiplexing of application code.
- **Suspend across a framework boundary**: Do not call `Fiber::suspend()` from inside a controller, middleware, listener, or job. `FiberScheduler` resumes a bare suspend only when the fiber's socket is readable, so a suspend it cannot answer parks the request until the connection times out.

### MAY

- **Use scoped Fibers for context isolation**: Extensions may create Fibers for the same purpose Studio does - isolating state per execution context - as long as:
- The Fiber is created, suspended/resumed, and completed within a single well-defined scope.
- Proper RAII cleanup is used (close all `ContextScope` guards before the Fiber completes).
- The Fiber does not escape into user-space or persist beyond the request.
- **Key per-request state by Fiber**: An extension singleton that holds request state should key it by `Fiber::getCurrent()` with a stable root fallback, as the framework's own holders do. That is what makes it safe under `FanOut` and under a test harness that wraps calls in Fibers.

### SHOULD

- **Prefer sequential execution**: When an extension needs to perform multiple I/O operations, execute them sequentially. If throughput is a concern, dispatch work to the queue system instead.
- **Document any Fiber usage**: If an extension creates Fibers for any reason, document the purpose, lifecycle, and cleanup strategy.

## Guarantees

Pulsar provides the following concurrency guarantees:

1. **No concurrent requests**: A worker never has two requests in flight. The synchronous accept loop handles one connection at a time, and the Fiber path is capped at a single connection Fiber precisely so that this holds.
2. **No hidden scheduler over application code**: The two Fiber schedulers that exist — `FiberScheduler` in the persistent runtime and `FanOut` — are explicit and bounded. Neither multiplexes controllers, middleware, listeners, or jobs. Application code executes in the order it is called.
3. **Deterministic execution order**: For any given input, the framework produces the same sequence of operations every time. There are no race conditions within a single request.
4. **RAII safety**: `ContextScope` guards ensure correlation contexts are properly cleaned up, even if exceptions are thrown. The guard validates Fiber identity on close to prevent cross-Fiber scope corruption.
5. **No implicit parallelism**: The framework never runs user code concurrently. Middleware, controllers, event listeners, and jobs execute one at a time.
6. **WeakMap lifecycle**: Fiber context entries are automatically reclaimed when the Fiber is garbage-collected. There are no memory leaks from abandoned Fibers.

## JIT compatibility

Pulsar's Fiber usage is compatible with both tracing and function JIT modes. The Fiber-keyed context holders never suspend or resume Fibers, so they have no JIT interaction edge cases. `FanOut` and `FiberScheduler` do suspend and resume Fibers; neither is on the request hot path — `FanOut` runs only for explicitly invoked infrastructure tasks (health probes, cache warming), and `FiberScheduler`'s suspend/resume points are the accept loop and the contended-lock wait — so any JIT deoptimization around those boundaries is confined to a bounded, non-hot scope rather than the request lifecycle.

- **Tracing JIT**: Works correctly. Pulsar's synchronous execution model produces predictable hot paths that the tracing JIT can optimize effectively.
- **Function JIT**: Works correctly. Individual function compilation is straightforward since there is no control-flow complexity from Fiber suspension in application code.
- **Preloaded classes**: Preloaded classes are JIT-compiled at server start, eliminating the first-request compilation cost. This applies to all hot-path classes included in the preload script.

See [`docs/deployment.md`](deployment.md) for JIT configuration and [`docs/performance.md`](performance.md) for benchmarking.
