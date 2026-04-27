# pulsar-supervisor

Supervisor tree + actor lifecycle + restart strategies inspired by Erlang/OTP supervision principles.

## What

Implements the Erlang/OTP supervision tree pattern: long-lived tokio tasks ("workers") are owned by a "supervisor" task whose policy specifies what to do when a worker exits abnormally — restart it, restart the entire group, or escalate to the parent supervisor. Restart strategies: `OneForOne`, `OneForAll`, `RestForOne`, `SimpleOneForOne` matching the OTP catalogue.

## Why

Erlang's "let it crash + supervisor restart" philosophy is the canonical pattern for resilient long-lived actor systems. Without an explicit supervision primitive every Pulsar deployment reimplements process management ad-hoc — usually with subtle bugs around restart-storm prevention + escalation. `pulsar-supervisor` makes the supervision tree first-class so deployments compose supervisors hierarchically and the policy is explicit + testable.

## How

`pulsar-supervisor` is a v2.3 dé-fusion of the v2.2 `pulsar-cluster` meta-crate per Decision 2.51. Workers and supervisors are tokio tasks; the supervisor watches its children via tokio's `JoinHandle::is_finished()` + receives ChildExitedSignals; restart-storm prevention via per-supervisor `max-restarts-per-window` budget. Capability gating on supervisor instantiation (only authorised callers can spawn supervisors at the root level).

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 3E.4 (historic `pulsar-cluster` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.50.1 + Section V.3E.4.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
