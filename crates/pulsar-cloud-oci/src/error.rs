//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// OCI API call failed.
    #[error("OCI API call failed: {0}")]
    Api(String),

    /// OCI Resource Principal / API key resolution failed.
    #[error("OCI credential resolution failed")]
    CredentialResolution,

    /// OCI signature v1 generation failed (private-key parse, hash mismatch).
    #[error("OCI request signature failed")]
    Signature,

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
