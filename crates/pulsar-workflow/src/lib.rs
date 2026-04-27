//! Typed-state workflow orchestration.
//!
//! Long-lived workflow primitive (Temporal / Cadence pattern) adapted to
//! Rust async + typed state machines:
//!
//! * Durable checkpointing — process restart resumes at the last
//!   successful step.
//! * Activities — atomic units of work with idempotency keys + retry
//!   policies + heartbeats.
//! * Signals — external events delivered into running workflows.
//! * Child workflows — composable by parent/child reference.
//! * Deterministic replay for activity-result reconstruction.
//!
//! Formal specification at `spec/orchestration.tla` (covers both
//! `pulsar-workflow` and `pulsar-saga`) proves state-reachability +
//! compensation-ordering invariants per Decision 2.20.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-orchestration` meta-crate per
//! Decision 2.51. See `docs/plan.md` Section IV.47.1 + Section V.3E.1.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
