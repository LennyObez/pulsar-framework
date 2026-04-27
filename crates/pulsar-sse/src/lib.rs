//! Server-Sent Events (SSE) push channel + reconnection state.
//!
//! WHATWG EventSource implementation on `pulsar-http`:
//!
//! * Per-event `id` / `retry` / `event` / `data` field emission.
//! * `Last-Event-ID` reconnection support — clients resume from the last
//!   successful event after a transient network drop.
//! * Stream multiplexing: a single TCP/HTTP-2 connection carries events
//!   for multiple subscriptions.
//! * Backpressure via bounded channel between event source and write loop.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-realtime` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.38.2 (per-crate spec) and Section V.3B.2.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
