//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// GCS API call failed.
    #[error("GCS API call failed: {0}")]
    Api(String),

    /// Object not found.
    #[error("GCS object not found: {bucket}/{name}")]
    NotFound { bucket: String, name: String },

    /// Retention-policy violation (deletion prevented by retention period).
    #[error("GCS retention violation: {reason}")]
    RetentionViolation { reason: String },

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
