//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Incident escalation failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Outbound webhook to an alerting backend failed (network, 5xx, timeout).
    #[error("escalation backend unreachable: {backend}")]
    BackendUnreachable { backend: String },

    /// Backend returned a 4xx (auth failure, malformed payload, rate-limit).
    #[error("escalation backend rejected payload: {backend} (HTTP {status})")]
    BackendRejected { backend: String, status: u16 },

    /// Capability token does not authorise the requested severity level.
    #[error("caller lacks capability for severity {severity}")]
    Forbidden { severity: u8 },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
