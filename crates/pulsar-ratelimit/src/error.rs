//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Rate-limit decision + backend failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Quota exhausted; the caller should retry after the indicated wait.
    #[error("quota exhausted (retry after {retry_after_ms} ms)")]
    QuotaExhausted { retry_after_ms: u64 },

    /// Backend (Redis) connection failure or timeout.
    #[error("rate-limit backend unavailable")]
    BackendUnavailable,

    /// Configuration error — invalid quota / window combination.
    #[error("invalid rate-limit configuration: {0}")]
    InvalidConfig(&'static str),
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
