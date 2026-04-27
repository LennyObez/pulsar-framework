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
    MultipartAborted {
        /// One-based part number (per RFC 5546) that triggered the abort.
        part: u32,
        /// Free-form reason the abort was triggered (e.g. `checksum-mismatch`, `network-timeout`, `caller-cancelled`).
        reason: String,
    },

    /// Object not found at the requested key.
    #[error("S3 object not found: {bucket}/{key}")]
    NotFound {
        /// S3-compatible bucket name (`my-bucket`).
        bucket: String,
        /// Object key within the bucket (`path/to/object.bin`).
        key: String,
    },
}

/// Crate-local `Result` alias per plan Section XVII.7.
pub type Result<T> = core::result::Result<T, Error>;
