# pulsar-resilience

Circuit breaker + bulkhead + retry + timeout primitives following the Hystrix / resilience4j patterns adapted to Rust async + `tower` middleware.

## What

Composable async primitives for failure-domain isolation:

* **Circuit breaker** — closed → open → half-open state machine with configurable failure-rate threshold + reset timeout.
* **Bulkhead** — semaphore-bounded concurrency per failure domain so one slow downstream cannot exhaust the request thread budget.
* **Retry** — exponential backoff with jitter + retry-budget caps per RFC 9110 § 8.5 idempotency markers.
* **Timeout** — per-call + per-batch timeouts with deadline propagation through `tracing` spans.

## Why

Distributed systems fail partially: a slow database, a flaky upstream, a network partition. Without resilience primitives every partial failure cascades — one slow downstream consumes all request threads, the application appears down even though only one dependency is degraded. Hystrix (Netflix, 2012) + resilience4j (2018) defined the canonical primitive set; Pulsar implements them as `tower::Layer` for composability across the framework.

## How

`pulsar-resilience` is a v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51. Each primitive is a `tower::Layer` so composition is `tower::ServiceBuilder::new().layer(circuit_breaker).layer(bulkhead).layer(retry).layer(timeout).service(...)`. State is in-memory single-instance; multi-instance state coordination is out of scope (use service mesh circuit-breakers for that).

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 1.5. See [`docs/plan.md`](../../docs/plan.md) Section IV.11.5 + Section V.1.5.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
