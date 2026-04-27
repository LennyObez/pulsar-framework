//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// WebTransport lifecycle + transport failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// HTTP/3 CONNECT upgrade to WebTransport rejected.
    #[error("WebTransport CONNECT rejected")]
    ConnectRejected,

    /// Capability check failed at session handshake.
    #[error("WebTransport capability check failed")]
    CapabilityRejected,

    /// QUIC stream errored (RST_STREAM, oversize, MAX_STREAMS exceeded).
    #[error("WebTransport stream error: {reason}")]
    StreamError { reason: &'static str },

    /// Datagram frame larger than the negotiated MAX_DATAGRAM_FRAME_SIZE.
    #[error("WebTransport datagram oversize ({len} > {max} bytes)")]
    DatagramOversize { len: usize, max: usize },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
