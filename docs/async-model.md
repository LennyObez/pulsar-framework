# Async model

## What Pulsar IS

Pulsar is a **synchronous request/response framework**:

- **HTTP handling**: One request in, one response out, fully sequential.
- **Scheduler**: Cron-based job scheduling. Each tick evaluates due jobs and runs them sequentially.
- **Workers**: Blocking worker model. A worker pops one job at a time, executes it to completion, then pops the next.
- **Console commands**: Run synchronously from start to finish.

All framework code executes in a single thread with deterministic ordering. There is no event loop, no implicit parallelism, and no hidden task scheduler.

## What Pulsar is NOT

- **Not an async framework**: Pulsar does not use an event loop (ReactPHP, Amp, Revolt, etc.).
- **Not a general-purpose Fiber scheduler**: Pulsar does not run a request-wide event loop that multiplexes Fibers for application concurrency. The sole exception is `FanOut` (see below), a bounded infrastructure-only primitive that creates, starts, and round-robin resumes Fibers within a single call frame for framework internals such as health probes.
- **Not implicitly parallel**: No framework code creates threads, forks processes, or runs background tasks behind the caller's back.

If you need concurrent I/O, use external workers (queue jobs, separate processes, or dedicated async runtimes). Pulsar handles the request path; concurrency lives outside the request lifecycle.

## Where fibers are used

Fibers are used in exactly two places: **Studio context isolation** and the **`FanOut` infrastructure concurrency primitive** (documented in its own section below).

### Components

| Component                    | File                                                   | Role                                                                 |
| ---------------------------- | ------------------------------------------------------ | -------------------------------------------------------------------- |
| `FiberScopedContextProvider` | `extensions/studio/src/FiberScopedContextProvider.php` | Manages per-Fiber correlation context stacks                         |
| `ContextScope`               | `extensions/studio/src/ContextScope.php`               | RAII guard that enters/exits a context scope                         |
| `FanOut`                     | `src/Concurrency/FanOut.php`                           | Bounded, infrastructure-only round-robin Fiber scheduler (see below) |

### How it works

`FiberScopedContextProvider` maintains a `WeakMap<object, SplStack<CorrelationContext>>` that maps Fiber identity to a stack of correlation contexts:

1. **Key selection**: When a Fiber is active (`Fiber::getCurrent()` returns non-null), the Fiber instance is used as the WeakMap key. When no Fiber is active, a stable root `stdClass` instance is used instead.
2. **Scope entry**: `enter()` pushes a new `CorrelationContext` onto the stack for the current key and returns a `ContextScope` guard.
3. **Scope exit**: When the `ContextScope` guard is closed (via `close()` or RAII), it pops the context from the stack. The guard enforces that `close()` is called from the same Fiber that called `enter()`.
4. **Garbage collection**: When a Fiber completes and is garbage-collected, its `WeakMap` entry is automatically reclaimed - no manual cleanup required.

### Why fibers here

Studio collectors (middleware interceptors, database query observers, etc.) may run inside Fibers created by third-party code or test harnesses. Without Fiber-scoped context, correlation IDs would leak between unrelated execution contexts. The `WeakMap` approach isolates each Fiber's observability state without requiring callers to pass context explicitly.

## Deterministic fallback

When no Fiber is active - which is the normal case for standard PHP-FPM and CLI requests - all context operations use the root `stdClass` key. This means:

- Context stacks behave identically to a simple global stack.
- No Fiber overhead is incurred (no `Fiber::getCurrent()` calls on the hot path beyond the null check).
- Sequential execution order is guaranteed.

The Fiber-aware code path activates only when Fibers are actually present, making the design zero-cost for the common case.

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

FanOut does not violate Pulsar's synchronous execution model: it provides structured, bounded concurrency within a single call frame, with deterministic completion ordering (tasks complete in the order they finish, results are returned in input key order).

## Extension guidelines

### MUST NOT

- **Create Fibers that escape their scope**: An extension must not create a Fiber and allow it to outlive the extension's `boot()` or request-handling lifecycle. Escaped Fibers break deterministic execution guarantees.
- **Start background threads**: PHP's threading model (via extensions like parallel) is not compatible with Pulsar's single-threaded assumptions. Do not use `parallel\Runtime` or similar constructs.
- **Assume implicit concurrency**: Do not write code that depends on concurrent execution. Pulsar provides no scheduling or multiplexing.

### MAY

- **Use scoped Fibers for context isolation**: Extensions may create Fibers for the same purpose Studio does - isolating state per execution context - as long as:
- The Fiber is created, suspended/resumed, and completed within a single well-defined scope.
- Proper RAII cleanup is used (close all `ContextScope` guards before the Fiber completes).
- The Fiber does not escape into user-space or persist beyond the request.

### SHOULD

- **Prefer sequential execution**: When an extension needs to perform multiple I/O operations, execute them sequentially. If throughput is a concern, dispatch work to the queue system instead.
- **Document any Fiber usage**: If an extension creates Fibers for any reason, document the purpose, lifecycle, and cleanup strategy.

## Guarantees

Pulsar provides the following concurrency guarantees:

1. **No hidden scheduler**: There is no event loop, task queue, or Fiber scheduler running behind the scenes. Code executes in the order it is called.
2. **Deterministic execution order**: For any given input, the framework produces the same sequence of operations every time. There are no race conditions within a single request.
3. **RAII safety**: `ContextScope` guards ensure correlation contexts are properly cleaned up, even if exceptions are thrown. The guard validates Fiber identity on close to prevent cross-Fiber scope corruption.
4. **No implicit parallelism**: The framework never runs user code concurrently. Middleware, controllers, event listeners, and jobs execute one at a time.
5. **WeakMap lifecycle**: Fiber context entries are automatically reclaimed when the Fiber is garbage-collected. There are no memory leaks from abandoned Fibers.

## JIT compatibility

Pulsar's Fiber usage (Studio context isolation and the `FanOut` infrastructure primitive) is compatible with both tracing and function JIT modes. Studio context isolation never suspends or resumes Fibers for concurrency, so it has no JIT interaction edge cases. `FanOut` does suspend and resume Fibers in a round-robin loop; its scheduling code path is not on the request hot path and runs only for explicitly invoked infrastructure tasks (health probes, cache warming), so any JIT deoptimization around its suspend/resume boundaries is confined to that bounded, non-hot scope rather than the request lifecycle.

- **Tracing JIT**: Works correctly. Pulsar's synchronous execution model produces predictable hot paths that the tracing JIT can optimize effectively.
- **Function JIT**: Works correctly. Individual function compilation is straightforward since there is no control-flow complexity from Fiber suspension.
- **Preloaded classes**: Preloaded classes are JIT-compiled at server start, eliminating the first-request compilation cost. This applies to all hot-path classes included in the preload script.

See [`docs/deployment.md`](deployment.md) for JIT configuration and [`docs/performance.md`](performance.md) for benchmarking.
