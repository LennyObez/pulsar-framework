# ADR-0005: Synchronous Core with Controlled Fiber Usage

## Status

**Superseded by [ADR-0071](0071-a-fiber-keyed-map-is-not-concurrency.md).**
The synchronous execution model below is unchanged and still governs. The Fiber rule is
not: thirteen files under `src/` and `extensions/` call the Fiber API, in three roles this
record does not distinguish, and ten of them do so purely to keep per-execution state
from bleeding between fibers. Exactly one suspends a fiber, and it is not either of the
two hash chains that used to. `--concurrency N` above 1 is now refused at construction
rather than approved; extensions are told to key request state by `Fiber::getCurrent()`
rather than forbidden the API; and the "architecture tests" this record claimed as
enforcement did not exist until ADR-0071 added one. Read ADR-0071 for what is in force
and `docs/async-model.md` for the full inventory.

The original decision history below is preserved for the record.

## Original status

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

Fibers are permitted only in two approved subsystems:

1. **Fiber scheduling** - `FiberScheduler` manages concurrent Fiber execution with exception isolation and resource cleanup. Each Fiber runs independently; uncaught exceptions in one Fiber do not propagate to or crash other Fibers.
2. **Persistent runtime connection multiplexing** - The `runtime:serve` command's `--concurrency N` flag uses Fibers for accepting multiple connections. Individual request handling within each Fiber remains sequential.

Fibers are not used in request business logic, middleware, controllers, or extension code.

Extensions are explicitly prohibited from:

- Creating Fiber schedulers or event loops.
- Using `Fiber::suspend()` to yield across framework boundaries.
- Assuming any particular Fiber execution context.

These restrictions are documented in `docs/async-model.md`. Enforcement is via code review and architecture tests; runtime guardrails are a future consideration.

## Consequences

### Positive

- **Deterministic execution.** Code runs in the order it appears. Stack traces are linear. Debugging follows standard PHP tooling.
- **Predictable resource usage.** Memory, CPU, and connection pools scale linearly with request count, not with concurrent task count.
- **Simpler mental model.** Developers reason about sequential code. No callback chains, no race conditions, no cancellation token propagation.
- **Zero-cost Fiber path.** Fiber infrastructure adds no overhead when Fibers are not in use.

### Negative

- **No concurrent I/O in the request path.** Multiple slow external calls (APIs, databases) execute sequentially. Fan-out patterns require queue-based parallelism via separate workers.
- **Higher latency for I/O-bound workloads.** Applications making many independent external calls per request pay the full sequential cost.
- **Extension limitations.** Extensions cannot use advanced Fiber patterns even when they would be beneficial (e.g., concurrent health checks).

### Neutral

- **External async runtimes** can be used alongside Pulsar for dedicated I/O-heavy workloads. The framework does not prevent this - it simply does not provide or manage async infrastructure.
