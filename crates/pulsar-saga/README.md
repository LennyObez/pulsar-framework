# pulsar-saga

Saga compensation engine: forward + compensating actions across distributed boundaries (Garcia-Molina + Salem 1987).

## What

Implements the classical saga pattern: a saga is a sequence of local transactions; if any local transaction fails, the saga executes the compensating transaction for every previously-completed step in reverse order. Each step is a forward-action paired with its compensating-action; the engine guarantees that on any failure the saga ends in either fully-applied or fully-compensated state — no partial commit is observable externally.

## Why

Cross-service business operations (place order → reserve inventory → charge payment → ship goods) span multiple persistence boundaries that distributed-transactions cannot atomically span. Sagas are the standard pattern for "distributed atomicity" in microservices + multi-resource workflows. The forward+compensate pairing is conceptually simple but ordering correctness under concurrent failures is subtle — `spec/orchestration.tla` (covering both `pulsar-workflow` and `pulsar-saga`) proves the compensation order is preserved even under concurrent commit + rollback events.

## How

`pulsar-saga` is a v2.3 dé-fusion of the v2.2 `pulsar-orchestration` meta-crate per Decision 2.51. Builds on top of `pulsar-workflow` (each saga step is implemented as an activity pair: forward + compensate). The compensation engine maintains a per-saga journal of completed steps so that on failure it can replay compensations in reverse. Storage backend follows `pulsar-workflow`.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 3E.1 (historic `pulsar-orchestration` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.47.2 + Section V.3E.1.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
