//! Token-bucket + leaky-bucket rate limiting.
//!
//! Implements both algorithms with composable quota dimensions
//! (per IP / per session / per API-key / per tenant / per route) and
//! pluggable backends (in-memory single-instance, Redis multi-instance
//! via atomic Lua refill+consume).
//!
//! Formal specification at `spec/ratelimit.tla` (added in v2.3 per
//! Decision 2.59) proves: refill monotonicity, no negative tokens,
//! per-client fairness conservation under concurrent consumers.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.11.4 (per-crate spec) and Section V.1.5.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
