//! WebSocket inbound dispatch + lifecycle + per-message backpressure.
//!
//! Implements RFC 6455 on `tokio-tungstenite`, layered with:
//!
//! * Per-connection state machine: `Connecting → Open → Closing → Closed`.
//! * Per-message backpressure via bounded channel between read loop and
//!   dispatcher (bounded queue prevents memory blow-up under slow consumer).
//! * Graceful close with RFC 6455 § 1.4 close-code preservation.
//! * Heartbeat ping/pong on configurable interval.
//! * Capability-token-bound session via `pulsar-auth`.
//!
//! Formal specification at `spec/websocket.tla` proves lifecycle soundness
//! under concurrent reads + writes + closes.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-realtime` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.38.1 (per-crate spec) and Section V.3B.2.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
