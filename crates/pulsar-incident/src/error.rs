//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Incident escalation failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Outbound webhook to an alerting backend failed (network, 5xx, timeout).
    #[error("escalation backend unreachable: {backend}")]
    BackendUnreachable {
        /// Identifier of the failing backend (e.g. `pagerduty`, `opsgenie`, `victorops`, `slack`).
        backend: String,
    },

    /// Backend returned a 4xx (auth failure, malformed payload, rate-limit).
    #[error("escalation backend rejected payload: {backend} (HTTP {status})")]
    BackendRejected {
        /// Identifier of the rejecting backend.
        backend: String,
        /// HTTP status code returned by the backend (typically 4xx).
        status: u16,
    },

    /// Capability token does not authorise the requested severity level.
    #[error("caller lacks capability for severity {severity}")]
    Forbidden {
        /// Numeric severity level the caller attempted (1 = Sev1 paging, 4 = Sev4 informational).
        severity: u8,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
