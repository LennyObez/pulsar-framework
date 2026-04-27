# pulsar-incident

Incident escalation + alert dispatch + on-call paging with PagerDuty / Opsgenie / VictorOps webhook adapters.

## What

Receives incident-class events from any Pulsar crate (security control trip, audit chain anomaly, SLO breach, fatal error) and routes them to one or more alerting backends per a configurable severity matrix. Each escalation step is recorded in the audit chain via `pulsar-audit` so post-incident retrospectives have a tamper-evident timeline.

## Why

Regulated-domain failure modes are denominated in regulatory exposure, not user inconvenience — a missed CSRF-trip alert in a banking deployment can mean a compromise of customer accounts before it is detected. Pulsar treats incident escalation as a first-class kernel-adjacent concern rather than a deployment-time convenience.

## How

`pulsar-incident` is a v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51. It depends on `pulsar-kernel` for the capability-token gating (only authorised callers can trigger high-severity escalations), on `pulsar-audit` for the timeline write, and on `reqwest` for the webhook dispatch. Adapter selection per Decision 2.22 hexagonal pattern.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 1.5 (historic `pulsar-guard` sprint). See [`docs/plan.md`](../../docs/plan.md) Section IV.11.3 (per-crate spec) and Section V.1.5 (implementing sprint).

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
