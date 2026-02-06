# Async Model

This document defines Pulsar's concurrency model, including what the framework does and does not support, where Fibers are used, and the guarantees extensions can rely on.

## What Pulsar IS

Pulsar is a **synchronous request/response framework**:

- **HTTP handling**: One request in, one response out, fully sequential.
- **Scheduler**: Cron-based job scheduling. Each tick evaluates due jobs and runs them sequentially.
- **Workers**: Blocking worker model. A worker pops one job at a time, executes it to completion, then pops the next.
- **Console commands**: Run synchronously from start to finish.

All framework code executes in a single thread with deterministic ordering. There is no event loop, no implicit parallelism, and no hidden task scheduler.

## What Pulsar is NOT

- **Not an async framework**: Pulsar does not use an event loop (ReactPHP, Amp, Revolt, etc.).
- **Not a Fiber scheduler**: Pulsar does not suspend, resume, or multiplex Fibers for concurrency.
- **Not implicitly parallel**: No framework code creates threads, forks processes, or runs background tasks behind the caller's back.

If you need concurrent I/O, use external workers (queue jobs, separate processes, or dedicated async runtimes). Pulsar handles the request path; concurrency lives outside the request lifecycle.

## Where Fibers Are Used

Fibers are used in exactly one place: **Studio context isolation**.

### Components

| Component                    | File                                        | Role                                         |
| ---------------------------- | ------------------------------------------- | -------------------------------------------- |
| `FiberScopedContextProvider` | `src/Studio/FiberScopedContextProvider.php` | Manages per-Fiber correlation context stacks |
| `ContextScope`               | `src/Studio/ContextScope.php`               | RAII guard that enters/exits a context scope |

### How It Works

`FiberScopedContextProvider` maintains a `WeakMap<object, SplStack<CorrelationContext>>` that maps Fiber identity to a stack of correlation contexts:

1. **Key selection**: When a Fiber is active (`Fiber::getCurrent()` returns non-null), the Fiber instance is used as the WeakMap key. When no Fiber is active, a stable root `stdClass` instance is used instead.
2. **Scope entry**: `enter()` pushes a new `CorrelationContext` onto the stack for the current key and returns a `ContextScope` guard.
3. **Scope exit**: When the `ContextScope` guard is closed (via `close()` or RAII), it pops the context from the stack. The guard enforces that `close()` is called from the same Fiber that called `enter()`.
4. **Garbage collection**: When a Fiber completes and is garbage-collected, its `WeakMap` entry is automatically reclaimed -- no manual cleanup required.

### Why Fibers Here

Studio collectors (middleware interceptors, database query observers, etc.) may run inside Fibers created by third-party code or test harnesses. Without Fiber-scoped context, correlation IDs would leak between unrelated execution contexts. The `WeakMap` approach isolates each Fiber's observability state without requiring callers to pass context explicitly.

## Deterministic Fallback

When no Fiber is active -- which is the normal case for standard PHP-FPM and CLI requests -- all context operations use the root `stdClass` key. This means:

- Context stacks behave identically to a simple global stack.
- No Fiber overhead is incurred (no `Fiber::getCurrent()` calls on the hot path beyond the null check).
- Sequential execution order is guaranteed.

The Fiber-aware code path activates only when Fibers are actually present, making the design zero-cost for the common case.

## Extension Guidelines

### MUST NOT

- **Create Fibers that escape their scope**: An extension must not create a Fiber and allow it to outlive the extension's `boot()` or request-handling lifecycle. Escaped Fibers break deterministic execution guarantees.
- **Start background threads**: PHP's threading model (via extensions like parallel) is not compatible with Pulsar's single-threaded assumptions. Do not use `parallel\Runtime` or similar constructs.
- **Assume implicit concurrency**: Do not write code that depends on concurrent execution. Pulsar provides no scheduling or multiplexing.

### MAY

- **Use scoped Fibers for context isolation**: Extensions may create Fibers for the same purpose Studio does -- isolating state per execution context -- as long as:
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

## JIT Compatibility

Pulsar's Fiber usage (Studio context isolation only) is compatible with both tracing and function JIT modes. Because Pulsar does not implement a Fiber scheduler or suspend/resume Fibers for concurrency, there are no JIT interaction edge cases.

- **Tracing JIT**: Works correctly. Pulsar's synchronous execution model produces predictable hot paths that the tracing JIT can optimize effectively.
- **Function JIT**: Works correctly. Individual function compilation is straightforward since there is no control-flow complexity from Fiber suspension.
- **Preloaded classes**: Preloaded classes are JIT-compiled at server start, eliminating the first-request compilation cost. This applies to all hot-path classes included in the preload script.

See [`docs/DEPLOYMENT.md`](DEPLOYMENT.md) for JIT configuration and [`docs/PERFORMANCE.md`](PERFORMANCE.md) for benchmarking.
