# ADR-0005: Synchronous Core with Controlled Fiber Usage

## Status

Accepted

## Context

PHP's Fiber API (8.1+) enables cooperative multitasking. Frameworks like Amp, ReactPHP, and Revolt build full async runtimes on Fibers with event loops, promises, and concurrent I/O. The PHP ecosystem is split between synchronous and async paradigms, with significant complexity and debugging challenges in async code.

Pulsar targets regulated, mission-critical domains where deterministic execution order, simple debugging, and predictable resource usage are more valuable than raw concurrency throughput.

## Decision

Pulsar is a **synchronous request/response framework** by design. There is no event loop, no implicit parallelism, and no hidden task scheduler.

Execution model:

- **HTTP handling.** One request in, one response out, fully sequential.
- **Scheduler.** Cron-based. Each tick evaluates due jobs and runs them sequentially.
- **Workers.** Blocking model. A worker pops one job, executes it to completion, then pops the next.
- **Console commands.** Run synchronously from start to finish.

Fibers are used in exactly **one place**: Studio context isolation via `FiberScopedContextProvider`. This component uses a `WeakMap<object, SplStack<CorrelationContext>>` keyed by Fiber identity to isolate observability context when third-party code or test harnesses create Fibers. When no Fiber is active (the normal case), all operations use a stable root key with zero Fiber overhead.

Extensions are explicitly prohibited from:

- Creating Fiber schedulers or event loops.
- Using `Fiber::suspend()` to yield across framework boundaries.
- Assuming any particular Fiber execution context.

## Consequences

### Positive

- **Deterministic execution.** Code runs in the order it appears. Stack traces are linear. Debugging follows standard PHP tooling.
- **Predictable resource usage.** Memory, CPU, and connection pools scale linearly with request count, not with concurrent task count.
- **Simpler mental model.** Developers reason about sequential code. No callback chains, no race conditions, no cancellation token propagation.
- **Zero-cost Fiber path.** The WeakMap-based context isolation adds no overhead when Fibers are absent.

### Negative

- **No concurrent I/O in the request path.** Multiple slow external calls (APIs, databases) execute sequentially. Fan-out patterns require queue-based parallelism via separate workers.
- **Higher latency for I/O-bound workloads.** Applications making many independent external calls per request pay the full sequential cost.
- **Extension limitations.** Extensions cannot use advanced Fiber patterns even when they would be beneficial (e.g., concurrent health checks).

### Neutral

- **The persistent worker runtime** (`runtime:serve`) optionally uses Fibers for connection-level concurrency (accepting multiple connections), but individual request handling remains sequential. This is a controlled use that does not change the synchronous execution model within a request.
- **External async runtimes** can be used alongside Pulsar for dedicated I/O-heavy workloads. The framework does not prevent this — it simply does not provide or manage async infrastructure.
