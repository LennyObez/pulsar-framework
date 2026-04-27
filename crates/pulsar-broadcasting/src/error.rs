//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Broadcasting + presence tracking failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Pub/sub backend (Redis / NATS / Kafka) connection failed.
    #[error("broadcasting backend unavailable")]
    BackendUnavailable,

    /// Subscribe capability check failed.
    #[error("subscribe capability rejected for channel: {channel}")]
    SubscribeRejected { channel: String },

    /// Channel name does not match the configured naming convention (e.g. tenant-prefix).
    #[error("invalid channel name: {0}")]
    InvalidChannel(String),
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
