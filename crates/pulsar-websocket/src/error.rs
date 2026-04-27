//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// WebSocket lifecycle + dispatch failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// HTTP upgrade negotiation failed (missing/invalid `Upgrade` / `Sec-WebSocket-*` headers).
    #[error("WebSocket upgrade rejected")]
    UpgradeRejected,

    /// Frame parsing failed (malformed wire bytes, oversize frame, invalid op-code).
    #[error("WebSocket frame parse error: {reason}")]
    FrameParse { reason: &'static str },

    /// Backpressure queue full — slow consumer cannot keep up; connection closed (RFC 6455 close 1011).
    #[error("WebSocket backpressure overflow; closing connection")]
    BackpressureOverflow,

    /// Heartbeat (ping/pong) timeout; connection presumed dead, closed (RFC 6455 close 1006).
    #[error("WebSocket heartbeat timeout after {idle_ms} ms idle")]
    HeartbeatTimeout { idle_ms: u64 },

    /// Capability-token verification failed at handshake; downgrade attack or expired session.
    #[error("WebSocket capability check failed at handshake")]
    CapabilityRejected,
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
