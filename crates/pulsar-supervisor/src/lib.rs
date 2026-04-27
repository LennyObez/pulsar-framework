//! Supervisor tree + actor lifecycle + restart strategies.
//!
//! Erlang/OTP-inspired supervision pattern adapted to Rust + tokio:
//!
//! * Worker tasks owned by supervisor tasks.
//! * Restart strategies: `OneForOne`, `OneForAll`, `RestForOne`,
//!   `SimpleOneForOne` matching the OTP catalogue.
//! * Restart-storm prevention: per-supervisor `max-restarts-per-window`
//!   budget; on budget exhaustion the supervisor itself escalates to its
//!   parent.
//! * Capability gating on supervisor instantiation at root level.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-cluster` meta-crate per
//! Decision 2.51. See `docs/plan.md` Section IV.50.1 + Section V.3E.4.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
