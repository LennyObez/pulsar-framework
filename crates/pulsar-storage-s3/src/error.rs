//! Crate-level error type per plan Section XVII.7 error strategy.

use thiserror::Error;

/// Driver-specific failure modes.
#[derive(Debug, Error)]
#[non_exhaustive]
pub enum Error {
    /// S3 API call failed.
    #[error("S3 API call failed: {0}")]
    Api(String),

    /// Multipart upload aborted mid-stream.
    #[error("S3 multipart upload aborted at part {part}: {reason}")]
    MultipartAborted { part: u32, reason: String },

    /// Object not found at the requested key.
    #[error("S3 object not found: {bucket}/{key}")]
    NotFound { bucket: String, key: String },

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
