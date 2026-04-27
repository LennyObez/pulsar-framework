//! Saga compensation engine.
//!
//! Classical saga pattern (Garcia-Molina + Salem 1987): each step is a
//! forward-action paired with its compensating-action; on any failure the
//! engine replays compensations in reverse order so the saga ends in
//! either fully-applied or fully-compensated state.
//!
//! Built on `pulsar-workflow`: each saga step is an activity pair
//! (forward + compensate). Per-saga journal of completed steps drives
//! compensation replay.
//!
//! Formal specification at `spec/orchestration.tla` (covers both
//! `pulsar-workflow` and `pulsar-saga`) plus `spec/multi-region-saga.tla`
//! (added in v2.3 per Decision 2.59) prove compensation ordering even
//! under concurrent failures + cross-region commits.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-orchestration` meta-crate per
//! Decision 2.51. See `docs/plan.md` Section IV.47.2 + Section V.3E.1.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
