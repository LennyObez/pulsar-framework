//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// Azure API call failed.
    #[error("Azure API call failed: {0}")]
    Api(String),

    /// Managed Identity / Service Principal credential resolution failed.
    #[error("Azure credential resolution failed")]
    CredentialResolution,

    /// Azure RBAC permission denied.
    #[error("Azure permission denied: {action}")]
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
