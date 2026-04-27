//! Broadcasting + presence tracking across realtime channels.
//!
//! Cross-instance fan-out layer. Composes with `pulsar-websocket`,
//! `pulsar-sse`, and `pulsar-webtransport` so a published event reaches
//! every subscribed client regardless of terminating instance.
//!
//! Backend defaults to Redis pub/sub; pluggable per Decision 2.22
//! hexagonal pattern (NATS or Kafka substitute).
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-realtime` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.38.4 (per-crate spec) and Section V.3B.2.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
