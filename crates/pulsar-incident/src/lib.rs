//! Incident escalation + alert dispatch + on-call paging.
//!
//! Routes incident-class events to one or more alerting backends per a
//! configurable severity matrix. Every escalation step is recorded in the
//! audit chain via `pulsar-audit` for tamper-evident post-incident
//! retrospectives.
//!
//! Backends: PagerDuty / Opsgenie / VictorOps / Slack / generic webhook.
//! Severity levels: `Sev1` (page on-call), `Sev2` (escalate within hour),
//! `Sev3` (next business hour), `Sev4` (informational).
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.11.3 (per-crate spec) and Section V.1.5.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
