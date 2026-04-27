# pulsar-workflow

Typed-state workflow orchestration: durable activity scheduling, retries, child workflows, signals. Formally specified jointly with `pulsar-saga` in `spec/orchestration.tla` per Decision 2.59.

## What

Long-lived workflow primitive following the Temporal / Cadence pattern adapted to Rust async + typed state machines. Each workflow is a `tokio` task whose state is checkpointed durably (PostgreSQL via `pulsar-orm` by default) so a process restart resumes the workflow at the last successful step. Activities are unit-of-work atoms executed inside the workflow; each activity has an idempotency key + retry policy + heartbeat. Signals carry external events into running workflows (e.g. "approval received", "cancellation requested").

## Why

Distributed business logic — order placement, KYC onboarding, account provisioning — is naturally a sequence of long-running steps with retries + branching. Without a workflow primitive each application reimplements this with ad-hoc cron jobs + queues + state columns; the result is brittle and untestable. Workflow primitives (Temporal, Cadence, AWS Step Functions) make these flows first-class. Pulsar formalises the state-reachability + saga-compensation invariants in TLA+ so cross-step correctness is machine-checked.

## How

`pulsar-workflow` is a v2.3 dé-fusion of the v2.2 `pulsar-orchestration` meta-crate per Decision 2.51. The workflow definition is a Rust `async` function annotated `#[workflow]`; the macro emits the durability checkpoint code + the deterministic-replay machinery. Storage backend defaults to PostgreSQL via `pulsar-orm-postgres` (workflow-state tables managed by the orchestrator); pluggable per Decision 2.22.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 3E.1 (historic `pulsar-orchestration` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.47.1 + Section V.3E.1.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
