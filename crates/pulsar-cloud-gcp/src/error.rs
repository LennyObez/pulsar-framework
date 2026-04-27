//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// GCP API call failed.
    #[error("GCP API call failed: {0}")]
    Api(String),

    /// Application Default Credentials / Workload Identity resolution failed.
    #[error("GCP credential resolution failed")]
    CredentialResolution,

    /// GCP IAM permission denied.
    #[error("GCP permission denied: {action}")]
    PermissionDenied { action: String },

    #[doc(hidden)]
    #[error("placeholder smoke variant")]
    __PlaceholderSmokeOnly,
}

impl Error {
    #[doc(hidden)]
    pub(crate) fn __placeholder_smoke_only() -> Self { Self::__PlaceholderSmokeOnly }
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
