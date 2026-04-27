//! WebTransport bidirectional streams + datagrams over HTTP/3 (QUIC).
//!
//! W3C WebTransport on top of HTTP/3:
//!
//! * Bidirectional streams (reliable, ordered).
//! * Unidirectional streams (reliable, ordered).
//! * Datagrams (unreliable, unordered, low-latency — no flow control).
//! * Capability-token-bound session via `pulsar-auth`.
//!
//! v2.3 dé-fusion of the v2.2 `pulsar-realtime` meta-crate per Decision 2.51.
//! See `docs/plan.md` Section IV.38.3 (per-crate spec) and Section V.3B.2.
//!
//! Placeholder release for namespace reservation.

pub mod error;
pub mod prelude;
mod sealed;

/// Crate version constant emitted from the workspace package version.
pub const VERSION: &str = env!("CARGO_PKG_VERSION");
